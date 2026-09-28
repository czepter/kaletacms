// Kaleta – rename of JavaScript bindings (variables, functions, parameters, catch params), the JS side of tools/rename.php.
// Property names (obj.obsah, { obsah: 1 }) are never touched: they are build JSON keys and DOM/API names; a shorthand
// property keeps its key ({ obsah } -> { obsah: content }). Refuses a map that merges two names in one file or breaks parsing.
// Needs the acorn parser outside the repository:  npm i --prefix /tmp/acorn acorn acorn-walk
//   NODE_PATH=/tmp/acorn/node_modules node tools/rename-js.mjs --inventory image/*.js
//   NODE_PATH=/tmp/acorn/node_modules node tools/rename-js.mjs tools/rename/3b-js.json [--apply] image/*.js
import fs from 'node:fs';
import { createRequire } from 'node:module';

const require = createRequire(`${process.env.NODE_PATH || process.cwd()}/`);
const acorn = require('acorn');
const walk = require('acorn-walk');

const args = process.argv.slice(2);
const inventory = args[0] === '--inventory';
const map = inventory ? {} : JSON.parse(fs.readFileSync(args[0], 'utf8'));
const apply = args.includes('--apply');
const files = args.filter((a, i) => (inventory ? i > 0 : i > 0) && a !== '--apply');

function parse(code) {
  return acorn.parse(code, { ecmaVersion: 'latest', sourceType: 'script', allowHashBang: true, locations: true });
}

// names bound by a pattern (declarations, params)
function patternNames(p, out) {
  if (!p) return out;
  switch (p.type) {
    case 'Identifier': out.push(p); break;
    case 'ObjectPattern': for (const prop of p.properties) patternNames(prop.type === 'RestElement' ? prop.argument : prop.value, out); break;
    case 'ArrayPattern': for (const e of p.elements) patternNames(e, out); break;
    case 'RestElement': patternNames(p.argument, out); break;
    case 'AssignmentPattern': patternNames(p.left, out); break;
  }
  return out;
}

function declared(ast) {
  const names = new Set();
  const add = (p) => patternNames(p, []).forEach((id) => names.add(id.name));
  walk.full(ast, (node) => {
    if (node.type === 'VariableDeclarator') add(node.id);
    if ((node.type === 'FunctionDeclaration' || node.type === 'FunctionExpression' || node.type === 'ClassDeclaration') && node.id) names.add(node.id.name);
    if (/Function/.test(node.type)) node.params.forEach(add);
    if (node.type === 'CatchClause' && node.param) add(node.param);
  });
  return names;
}

// every Identifier that is a binding or a reference (not a property key or member property)
function identifiers(ast) {
  const ids = [];
  const shorthand = new Set();
  walk.fullAncestor(ast, (node, ancestors) => {
    if (node.type !== 'Identifier') return;
    const parent = ancestors[ancestors.length - 2];
    if (parent) {
      if (parent.type === 'MemberExpression' && parent.property === node && !parent.computed) return;
      if ((parent.type === 'Property' || parent.type === 'PropertyDefinition' || parent.type === 'MethodDefinition') && parent.key === node && !parent.computed) {
        if (parent.shorthand) shorthand.add(node.start); // the value node (same position) carries the binding
        return;
      }
      if (parent.type === 'LabeledStatement' || parent.type === 'BreakStatement' || parent.type === 'ContinueStatement') return;
    }
    ids.push(node);
  });
  return { ids, shorthand };
}

let problems = 0;
let total = 0;
const inv = {};
const allDeclared = new Set();
const parsed = files.map((f) => {
  const code = fs.readFileSync(f, 'utf8');
  const ast = parse(code);
  const d = declared(ast);
  d.forEach((n) => allDeclared.add(n));
  return { f, code, ast, d };
});
if (inventory) {
  for (const { f, d } of parsed) inv[f] = [...d].sort();
  console.log(JSON.stringify(inv, null, 1));
  process.exit(0);
}
for (const { f, code, ast, d } of parsed) {
  const { ids, shorthand } = identifiers(ast);
  const used = new Set(ids.map((i) => i.name));
  // collisions: a new name that already exists in the file as something else, or two old names to one new
  const seen = {};
  for (const name of used) {
    const n = map[name] && allDeclared.has(name) ? map[name] : name;
    if (seen[n] && seen[n] !== name) { console.log(`${f}: „${seen[n]}“ and „${name}“ would both become „${n}“`); problems++; }
    seen[n] = name;
  }
  const edits = [];
  const done = new Set();
  for (const id of ids) {
    const n = map[id.name];
    if (!n || !allDeclared.has(id.name) || done.has(id.start)) continue;
    done.add(id.start);
    edits.push([id.start, id.end, shorthand.has(id.start) ? `${id.name}: ${n}` : n]);
  }
  edits.sort((a, b) => b[0] - a[0]);
  let out = code;
  for (const [s, e, t] of edits) out = out.slice(0, s) + t + out.slice(e);
  total += edits.length;
  try { parse(out); } catch (e) { console.log(`${f}: result does not parse – ${e.message}`); problems++; }
  if (apply && out !== code) fs.writeFileSync(f, out);
  console.log(`${f}: ${edits.length} references`);
}
const unused = Object.keys(map).filter((k) => !allDeclared.has(k));
if (unused.length) console.log('not declared anywhere (left alone): ' + unused.join(', '));
console.log(`${total} references, ${problems} problems${apply ? '' : ' – dry run'}`);
process.exit(problems ? 1 : 0);
