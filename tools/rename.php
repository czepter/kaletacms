<?php
/**
 * Kaleta – map-driven renaming of PHP identifiers (Czech → English, see docs/glossary.md).
 *
 *   php tools/rename.php <map.php>            dry run: what would change, collisions, string literals to review
 *   php tools/rename.php <map.php> --apply    rewrite the files and move class files (git mv)
 *   php tools/rename.php --inventory          declared identifiers (classes, functions, constants, properties, variables)
 *   php tools/rename.php --self-test
 *
 * A map file returns:
 *   'namespaces' => ['Kaleta\Stavitel\Prvky' => 'Kaleta\Builder\Elements', ...]  longest prefix wins
 *   'classes'    => ['Kaleta\Stavitel\Prvky\Nadpis' => 'Heading', ...]           new short name (namespace from 'namespaces')
 *   'names'      => ['nainstaluj' => 'install', 'JEN_CASTI' => 'PARTS_ONLY', ...] methods, functions, constants, properties
 *                                                                                and variables – one translation everywhere
 *   'functions'  => ['datum' => 'format_date', ...]      global functions only (calls and declarations outside classes), so a
 *                                                    helper and a method of the same Czech name can get different names
 *   'vars'       => ['chyb' => 'errors', ...]          local variables only (never members: a property that is in 'vars'
 *                                                    but not in 'names' is refused)
 *   'paths'      => ['tools/', 'system/src/Core/']   optional: rewrite only files under these paths
 *   'files'      => ['tools/testy.php' => 'tools/unit-tests.php']   optional: other files to move (git mv), with their
 *                                                                   paths in string literals of PHP files
 *
 * Works on tokens, not text: string literals are never changed (database columns, build JSON keys, settings keys, URLs and
 * MCP tool names are contracts), comments only in docblock tags (@param, @var, @return …). Variables are renamed in classes
 * and scripts but not in views (system/views, layout), whose variables come from the string keys passed to render().
 * Before writing it refuses the map when two variables of one function, two members of one class, or a method and an
 * inherited one would end up with the same name.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('CLI only.');
}

final class Rename
{
    /** @var array<string, string> */
    private array $namespaces;
    /** @var array<string, string> old FQCN => new FQCN */
    private array $classes = [];
    /** @var array<string, string> */
    private array $names;
    /** @var array<string, string> */
    private array $functions;
    /** @var array<string, string> names + local variables: what a variable token may become */
    private array $vars;
    /** @var list<string> */
    public array $problems = [];
    /** @var array<string, list<string>> old name => places in string literals, JS and CSS */
    public array $stringHits = [];
    /** @var array<string, int> */
    public array $counts = [];
    /** @var array<string, string> old path => new path of moved class files and namespace folders (system/src/…) */
    private array $paths = [];

    /**
     * @param array{namespaces?: array<string, string>, classes?: array<string, string>, names?: array<string, string>, files?: array<string, string>, paths?: list<string>} $map
     * @param list<string> $knownClasses FQCNs declared in the project
     */
    public function __construct(array $map, array $knownClasses)
    {
        $this->namespaces = $map['namespaces'] ?? [];
        uksort($this->namespaces, fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        $this->names = $map['names'] ?? [];
        $this->functions = $map['functions'] ?? [];
        $this->vars = ($map['vars'] ?? []) + $this->names; // a variable takes its 'vars' name first, so a method can be a verb and a variable a noun
        foreach ($knownClasses as $fqcn) {
            $pos = strrpos($fqcn, '\\');
            $ns = $pos === false ? '' : substr($fqcn, 0, $pos);
            $short = $map['classes'][$fqcn] ?? substr($fqcn, $pos === false ? 0 : $pos + 1);
            $new = ltrim($this->namespace($ns) . '\\' . $short, '\\');
            if ($new !== $fqcn) {
                $this->classes[$fqcn] = $new;
            }
        }
        $path = fn (string $fqcn): string => 'system/src/' . str_replace('\\', '/', substr($fqcn, 7));
        foreach ($this->classes as $old => $new) {
            if (str_starts_with($old, 'Kaleta\\') && str_starts_with($new, 'Kaleta\\')) {
                $this->paths[$path($old) . '.php'] = $path($new) . '.php';
            }
        }
        foreach ($this->namespaces as $old => $new) {
            if (str_starts_with($old, 'Kaleta\\') && str_starts_with($new, 'Kaleta\\')) {
                $this->paths[$path($old)] = $path($new);
            }
        }
        $this->paths += $map['files'] ?? [];
        uksort($this->paths, fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        foreach (array_keys($map['classes'] ?? []) as $fqcn) {
            if (!in_array($fqcn, $knownClasses, true)) {
                $this->problems[] = "map: unknown class $fqcn";
            }
        }
        foreach ($this->vars as $old => $new) {
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $new)) {
                $this->problems[] = "map: invalid name „{$new}“ for $old";
            }
        }
    }

    /** @return array<string, string> old FQCN => new FQCN */
    public function classMap(): array
    {
        return $this->classes;
    }

    public function namespace(string $ns): string
    {
        foreach ($this->namespaces as $old => $new) {
            if ($ns === $old || str_starts_with($ns, $old . '\\')) {
                return $new . substr($ns, strlen($old));
            }
        }

        return $ns;
    }

    /** Rewrites one PHP source. $view: variables stay (they come from render() string keys), only static properties change. */
    public function rewrite(string $code, string $file, bool $view): string
    {
        $t = PhpToken::tokenize($code);
        $n = count($t);
        $ns = '';
        $imports = []; // alias => FQCN
        $depth = 0;
        $paren = 0;
        $classBodies = []; // brace depths of class bodies (properties are declared right inside)
        $classPending = false;
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $x = $t[$i];
            $text = $x->text;
            if ($x->text === '{' || $x->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $depth++;
                if ($classPending && $x->text === '{') {
                    $classBodies[] = $depth;
                    $classPending = false;
                }
            } elseif ($x->text === '}') {
                if ($classBodies !== [] && end($classBodies) === $depth) {
                    array_pop($classBodies);
                }
                $depth--;
            } elseif ($x->text === '(') {
                $paren++;
            } elseif ($x->text === ')') {
                $paren--;
            } elseif ($x->is([T_CLASS, T_TRAIT, T_ENUM, T_INTERFACE]) && ($p = $this->prev($t, $i)) !== null && !$t[$p]->is(T_DOUBLE_COLON)) {
                $classPending = true;
            }
            if ($x->is(T_NAMESPACE) && ($next = $this->next($t, $i)) !== null && $t[$next]->is([T_STRING, T_NAME_QUALIFIED])) {
                $ns = $t[$next]->text;
                $out[] = $text;
                for ($j = $i + 1; $j < $next; $j++) {
                    $out[] = $t[$j]->text;
                }
                $out[] = $this->namespace($ns);
                $this->count('namespace ' . $ns, $this->namespace($ns) !== $ns);
                $i = $next;
                continue;
            }
            if ($x->is(T_USE) && $depth === 0 && ($next = $this->next($t, $i)) !== null && $t[$next]->text !== '(') {
                // top-level import (a closure's use (...) in a script is not one): use A\B; use A\B as C; use A\{B, C as D}; (use function / use const pass through)
                $end = $i;
                while ($end < $n && $t[$end]->text !== ';') {
                    $end++;
                }
                $out[] = $this->rewriteUse(array_slice($t, $i, $end - $i + 1), $imports);
                $i = $end;
                continue;
            }
            if ($x->is([T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED, T_STRING]) && !$this->isMember($t, $i)) {
                $resolved = $this->resolve($text, $x->id, $ns, $imports);
                if ($resolved !== null && isset($this->classes[$resolved])) {
                    $out[] = $this->refer($this->classes[$resolved], $text, $x->id, $ns, $imports, $resolved);
                    $this->count($resolved, true);
                    continue;
                }
            }
            if ($x->is(T_STRING) && isset($this->functions[$text]) && ($p = $this->prev($t, $i)) !== null
                && !$t[$p]->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_CONST]) && ($classBodies === [] || !$t[$p]->is(T_FUNCTION))
                && ($next = $this->next($t, $i)) !== null && $t[$next]->text === '(') {
                $out[] = $this->functions[$text]; // a global function: its call or its declaration outside a class
                $this->count($text, true);
                continue;
            }
            if ($x->is(T_STRING) && $this->isNamedArgument($t, $i) && isset($this->vars[$text])) {
                $out[] = $this->vars[$text]; // a named argument is the parameter's variable name
                $this->count($text, true);
                continue;
            }
            if ($x->is(T_STRING) && isset($this->names[$text]) && !$this->isClassContext($t, $i)) {
                $out[] = $this->names[$text];
                $this->count($text, true);
                continue;
            }
            if ($x->is(T_VARIABLE) && $text !== '$this' && isset($this->vars[substr($text, 1)])) {
                $name = substr($text, 1);
                $prev = $this->prev($t, $i);
                $static = $prev !== null && $t[$prev]->is(T_DOUBLE_COLON);
                $property = $static || ($classBodies !== [] && end($classBodies) === $depth && ($paren === 0 || $this->promoted($t, $i)));
                if ($property && !isset($this->names[$name])) {
                    $this->problems[] = "$file:{$x->line}: \${$name} is a property – put it in 'names', not 'vars'";
                } elseif ($property && $this->vars[$name] !== $this->names[$name] && !$static && $paren > 0) {
                    $this->problems[] = "$file:{$x->line}: promoted constructor property \${$name} is also a variable – 'vars' and 'names' must agree";
                } elseif ($property || !$view) {
                    $out[] = '$' . ($property ? $this->names[$name] : $this->vars[$name]);
                    $this->count($name, true);
                    continue;
                }
            }
            if ($x->is(T_DOC_COMMENT)) {
                $out[] = $this->rewriteDoc($text, $ns, $imports, $view);
                continue;
            }
            if ($x->is(T_CONSTANT_ENCAPSED_STRING) && isset($this->names[$member = substr($text, 1, -1)]) && $this->isMemberNameArgument($t, $i)) {
                $out[] = $text[0] . $this->names[$member] . $text[0]; // new ReflectionMethod(X::class, 'name'), method_exists($x, 'name')
                $this->count($member, true);
                continue;
            }
            if ($x->is([T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE])) {
                // the one change inside strings: paths of moved class files and folders (tests, tools)
                $moved = $this->paths !== [] ? $this->movePaths($text) : $text;
                $this->count('path', $moved !== $text);
                if ($moved !== $text) {
                    $out[] = $moved;
                    continue;
                }
                $this->scanString($text, $file . ':' . $x->line);
            }
            $out[] = $text;
        }

        return implode('', $out);
    }

    /** @param list<PhpToken> $stmt @param array<string, string> $imports */
    private function rewriteUse(array $stmt, array &$imports): string
    {
        $kind = $stmt[1]->is(T_WHITESPACE) && isset($stmt[2]) && in_array(strtolower($stmt[2]->text), ['function', 'const'], true) ? 'other' : 'class';
        $code = implode('', array_map(fn (PhpToken $x): string => $x->text, $stmt));
        if ($kind !== 'class') {
            return $code;
        }
        $body = trim(substr($code, 3, -1));
        if (preg_match('/^(.*)\\\\\{(.*)\}$/s', $body, $m)) {
            $prefix = ltrim($m[1], '\\');
            $parts = [];
            foreach (explode(',', $m[2]) as $part) {
                if (trim($part) === '') {
                    continue;
                }
                [$name, $alias] = $this->splitAlias(trim($part));
                $old = $prefix . '\\' . $name;
                $imports[$alias ?? $this->short($name)] = $old;
                $new = $this->classes[$old] ?? $old;
                $this->count($old, isset($this->classes[$old]));
                $parts[] = [$new, $alias];
            }
            // a group import cannot span namespaces after a move: write one import per class
            $lines = array_map(fn (array $p): string => 'use ' . $p[0] . ($p[1] !== null ? ' as ' . $p[1] : '') . ';', $parts);

            return implode("\n", $lines);
        }
        [$name, $alias] = $this->splitAlias($body);
        $old = ltrim($name, '\\');
        $imports[$alias ?? $this->short($old)] = $old;
        if (!isset($this->classes[$old])) {
            return $code;
        }
        $this->count($old, true);

        return 'use ' . $this->classes[$old] . ($alias !== null ? ' as ' . $alias : '') . ';';
    }

    /** @return array{0: string, 1: ?string} */
    private function splitAlias(string $part): array
    {
        return preg_match('/^(\S+)\s+as\s+(\w+)$/i', $part, $m) ? [$m[1], $m[2]] : [$part, null];
    }

    private function short(string $fqcn): string
    {
        $pos = strrpos($fqcn, '\\');

        return $pos === false ? $fqcn : substr($fqcn, $pos + 1);
    }

    /** @param array<string, string> $imports */
    private function resolve(string $name, int $id, string $ns, array $imports): ?string
    {
        if ($id === T_NAME_FULLY_QUALIFIED) {
            return substr($name, 1);
        }
        if ($id === T_NAME_QUALIFIED) {
            $first = strstr($name, '\\', true);

            return isset($imports[$first]) ? $imports[$first] . substr($name, strlen($first)) : ltrim($ns . '\\' . $name, '\\');
        }
        if (!preg_match('/^[A-Z][A-Za-z0-9]*[a-z]/', $name)) {
            return null; // classes are PascalCase (ZHtml too); CONSTANTS and methods are not
        }

        return $imports[$name] ?? ltrim($ns . '\\' . $name, '\\');
    }

    /** New way to write a reference to $new from a file in (old) namespace $ns. @param array<string, string> $imports */
    private function refer(string $new, string $text, int $id, string $ns, array $imports, string $old): string
    {
        $newNs = $this->namespace($ns);
        if ($id === T_STRING && isset($imports[$text])) {
            // imported: the import line is rewritten; an implicit alias follows the new short name
            return $this->short($old) === $text ? $this->short($new) : $text;
        }
        if ($id === T_NAME_FULLY_QUALIFIED) {
            return '\\' . $new;
        }
        if ($id === T_NAME_QUALIFIED && isset($imports[strstr($text, '\\', true)])) {
            $first = strstr($text, '\\', true);
            $base = $this->classes[$imports[$first]] ?? $imports[$first];
            if (str_starts_with($new, $base . '\\')) {
                return $first . substr($new, strlen($base));
            }

            return '\\' . $new;
        }
        if ($newNs === '') {
            return $new;
        }
        if (str_starts_with($new, $newNs . '\\')) {
            return substr($new, strlen($newNs) + 1);
        }

        return '\\' . $new;
    }

    /** The second argument of reflection and *_exists calls, which names a member. @param list<PhpToken> $t */
    private function isMemberNameArgument(array $t, int $i): bool
    {
        $prev = $this->prev($t, $i);
        if ($prev === null || $t[$prev]->text !== ',') {
            return false;
        }
        for ($j = $prev - 1, $paren = 0; $j >= 0; $j--) {
            if ($t[$j]->text === ')') {
                $paren++;
            } elseif ($t[$j]->text === '(') {
                if ($paren-- === 0) {
                    $fn = $this->prev($t, $j);

                    return $fn !== null && in_array(ltrim($t[$fn]->text, '\\'), ['ReflectionMethod', 'ReflectionProperty', 'ReflectionClassConstant', 'method_exists', 'property_exists'], true);
                }
            } elseif ($paren === 0 && $t[$j]->text === ',') {
                return false; // only the second argument
            }
        }

        return false;
    }

    /** name: in a call – f(nazev: 1). @param list<PhpToken> $t */
    private function isNamedArgument(array $t, int $i): bool
    {
        $next = $this->next($t, $i);
        $prev = $this->prev($t, $i);

        return $next !== null && $t[$next]->text === ':' && $prev !== null && in_array($t[$prev]->text, ['(', ','], true)
            && ($after = $this->next($t, $next)) !== null && $t[$after]->text !== ':';
    }

    /** A constructor parameter with a visibility or readonly modifier is also a property. @param list<PhpToken> $t */
    private function promoted(array $t, int $i): bool
    {
        for ($j = $i - 1; $j >= 0 && $t[$j]->text !== ',' && $t[$j]->text !== '('; $j--) {
            if ($t[$j]->is([T_PUBLIC, T_PROTECTED, T_PRIVATE, T_READONLY])) {
                return true;
            }
        }

        return false;
    }

    /** @param list<PhpToken> $t */
    private function isMember(array $t, int $i): bool
    {
        $prev = $this->prev($t, $i);

        return $prev !== null && ($t[$prev]->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_CONST]));
    }

    /** A T_STRING that names a class (never renamed as a member): before ::class, after new/extends/implements/instanceof. @param list<PhpToken> $t */
    private function isClassContext(array $t, int $i): bool
    {
        $prev = $this->prev($t, $i);

        return $prev !== null && $t[$prev]->is([T_NEW, T_EXTENDS, T_IMPLEMENTS, T_INSTANCEOF, T_NAMESPACE]);
    }

    /** @param array<string, string> $imports */
    private function rewriteDoc(string $doc, string $ns, array $imports, bool $view): string
    {
        return (string) preg_replace_callback('/^(\s*(?:\/\*\*)?\s*\*?\s*@(?:param|var|return|throws|property|method|template|extends|implements|phpstan-[a-z-]+|psalm-[a-z-]+)\b)(.*)$/m', function (array $m) use ($ns, $imports, $view): string {
            $rest = (string) preg_replace_callback('/(\\\\?[A-Z][A-Za-z0-9]*(?:\\\\[A-Za-z0-9]+)*)|\$([A-Za-z_][A-Za-z0-9_]*)|(?<=::)([A-Za-z_][A-Za-z0-9_]*)/', function (array $w) use ($ns, $imports, $view): string {
                if (($w[1] ?? '') !== '') {
                    $id = str_starts_with($w[1], '\\') ? T_NAME_FULLY_QUALIFIED : (str_contains($w[1], '\\') ? T_NAME_QUALIFIED : T_STRING);
                    $resolved = $this->resolve($w[1], $id, $ns, $imports);

                    return $resolved !== null && isset($this->classes[$resolved]) ? $this->refer($this->classes[$resolved], $w[1], $id, $ns, $imports, $resolved) : $w[1];
                }
                if (($w[2] ?? '') !== '') {
                    return !$view && isset($this->vars[$w[2]]) ? '$' . $this->vars[$w[2]] : $w[0];
                }

                return $this->names[$w[3]] ?? $w[3];
            }, $m[2]);

            return $m[1] . $rest;
        }, $doc);
    }

    /**
     * PHP inside shell scripts (php -r '…', heredocs): class names, class file paths and ::member( / ->member( calls.
     * Text-level, so only these safe shapes – variables inside the snippets are left alone.
     */
    public function rewriteShell(string $code): string
    {
        $code = $this->movePaths($code);
        foreach ($this->classes as $old => $new) {
            foreach (['\\\\', '\\'] as $sep) { // Kaleta\\Core\\X in double quotes, Kaleta\Core\X in single quotes
                $code = (string) preg_replace('/' . preg_quote(str_replace('\\', $sep, $old), '/') . '(?![A-Za-z0-9_])/', str_replace('\\', $sep, $new), $code);
            }
        }
        foreach ($this->names as $old => $new) {
            $code = (string) preg_replace('/(::|->)' . preg_quote($old, '/') . '(?=\()/', '$1' . $new, $code);
            if (preg_match('/^[A-Z][A-Z0-9_]*$/', $old)) {
                $code = (string) preg_replace('/::' . preg_quote($old, '/') . '(?![A-Za-z0-9_])/', '::' . $new, $code); // X::CONSTANT
            }
        }
        foreach ($this->functions as $old => $new) {
            $code = (string) preg_replace('/(?<![A-Za-z0-9_>:$\\-])' . preg_quote($old, '/') . '(?=\()/', $new, $code);
        }

        return $code;
    }

    public function movePaths(string $text): string
    {
        foreach ($this->paths as $old => $new) {
            $text = (string) preg_replace('#' . preg_quote($old, '#') . '(?![A-Za-z0-9_])#', $new, $text);
        }

        return $text;
    }

    private function scanString(string $text, string $place): void
    {
        foreach ($this->names as $old => $new) {
            if (strlen($old) > 3 && preg_match('/(?<![A-Za-z0-9_])' . preg_quote($old, '/') . '(?![A-Za-z0-9_])/', $text)) {
                $this->stringHits[$old][] = $place;
            }
        }
        foreach ($this->classes as $old => $new) {
            if (str_contains(str_replace('\\\\', '\\', $text), $old)) {
                $this->stringHits[$old][] = $place;
            }
        }
    }

    public function scanAsset(string $code, string $file): void
    {
        foreach (explode("\n", $code) as $no => $line) {
            foreach ($this->names as $old => $new) {
                if (strlen($old) > 3 && preg_match('/(?<![A-Za-z0-9_-])' . preg_quote($old, '/') . '(?![A-Za-z0-9_-])/', $line)) {
                    $this->stringHits[$old][] = $file . ':' . ($no + 1);
                }
            }
        }
    }

    private function count(string $name, bool $changed): void
    {
        if ($changed) {
            $this->counts[$name] = ($this->counts[$name] ?? 0) + 1;
        }
    }

    /** @param list<PhpToken> $t */
    private function prev(array $t, int $i): ?int
    {
        for ($j = $i - 1; $j >= 0; $j--) {
            if (!$t[$j]->isIgnorable()) {
                return $j;
            }
        }

        return null;
    }

    /** @param list<PhpToken> $t */
    private function next(array $t, int $i): ?int
    {
        for ($j = $i + 1, $n = count($t); $j < $n; $j++) {
            if (!$t[$j]->isIgnorable()) {
                return $j;
            }
        }

        return null;
    }

    /**
     * Variables of one function (including its closures and arrow functions) that would merge into one name.
     * Checked on the original code: every variable of the outermost function body, then mapped.
     */
    public function variableCollisions(string $code, string $file): void
    {
        $t = PhpToken::tokenize($code);
        $n = count($t);
        if (!preg_match('/^namespace\s/m', $code)) {
            // a script: its top level is one scope with its closures – checked as a whole (errs on the safe side)
            $all = [];
            foreach ($t as $x) {
                if ($x->is(T_VARIABLE) && $x->text !== '$this') {
                    $all[substr($x->text, 1)] = true;
                }
            }
            $this->collide(array_keys($all), $file, 'variables', $this->vars);
        }
        for ($i = 0; $i < $n; $i++) {
            if (!$t[$i]->is(T_FUNCTION)) {
                continue;
            }
            // find the body: first "{" after the parameter list, or ";" for abstract methods
            $j = $i;
            $paren = 0;
            while ($j < $n) {
                $c = $t[$j]->text;
                if ($c === '(') {
                    $paren++;
                } elseif ($c === ')') {
                    $paren--;
                } elseif ($paren === 0 && ($c === '{' || $c === ';')) {
                    break;
                }
                $j++;
            }
            if ($j >= $n || $t[$j]->text === ';') {
                continue;
            }
            $depth = 0;
            $vars = [];
            for ($k = $i; $k < $n; $k++) {
                if ($t[$k]->text === '{' || $t[$k]->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                    $depth++;
                } elseif ($t[$k]->text === '}') {
                    $depth--;
                    if ($depth === 0 && $k > $j) {
                        break;
                    }
                }
                if ($t[$k]->is(T_VARIABLE) && $t[$k]->text !== '$this') {
                    $prev = $this->prev($t, $k);
                    if ($prev === null || !$t[$prev]->is(T_DOUBLE_COLON)) {
                        $vars[substr($t[$k]->text, 1)] = true;
                    }
                }
            }
            $this->collide(array_keys($vars), $file . ':' . $t[$i]->line, 'variables', $this->vars);
            $i = $k; // nested functions were part of this scope
        }
    }

    /** @param list<string> $olds @param array<string, string>|null $map */
    private function collide(array $olds, string $place, string $what, ?array $map = null): void
    {
        $seen = [];
        foreach ($olds as $old) {
            $new = ($map ?? $this->names)[$old] ?? $old;
            if (isset($seen[$new]) && $seen[$new] !== $old) {
                $this->problems[] = "$place: $what „{$seen[$new]}“ and „{$old}“ would both become „{$new}“";
            }
            $seen[$new] = $old;
        }
    }

    /**
     * Members of each class (methods, constants, properties) and inherited methods that would merge into one name.
     *
     * @param array<string, array{parent: ?string, interfaces: list<string>, methods: list<string>, consts: list<string>, props: list<string>, file: string}> $classes
     */
    public function memberCollisions(array $classes): void
    {
        foreach ($classes as $fqcn => $c) {
            $this->collide($c['methods'], $c['file'] . " ($fqcn)", 'methods');
            $this->collide($c['consts'], $c['file'] . " ($fqcn)", 'constants');
            $this->collide($c['props'], $c['file'] . " ($fqcn)", 'properties');
            $ancestors = [];
            $queue = array_filter([$c['parent'], ...$c['interfaces']]);
            while ($queue !== []) {
                $a = array_shift($queue);
                if (isset($classes[$a]) && !isset($ancestors[$a])) {
                    $ancestors[$a] = true;
                    array_push($queue, ...array_filter([$classes[$a]['parent'], ...$classes[$a]['interfaces']]));
                }
            }
            foreach (array_keys($ancestors) as $a) {
                foreach ([['methods', 'method'], ['consts', 'constant']] as [$kind, $label]) {
                    foreach ($c[$kind] as $own) {
                        foreach ($classes[$a][$kind] as $inherited) {
                            if ($own !== $inherited && ($this->names[$own] ?? $own) === ($this->names[$inherited] ?? $inherited)) {
                                $this->problems[] = "{$c['file']} ($fqcn): $label „{$own}“ would override „{$inherited}“ of $a";
                            }
                        }
                    }
                }
            }
        }
    }
}

/**
 * Views (map key 'views'): template files, their variables and the keys of the data passed to them.
 *   'dirs'  => ['system/views/admin/', …]   templates whose files and variables are renamed
 *   'files' => ['vypis' => 'list', …]       template file names (basename without .php)
 *   'vars'  => ['stranky' => 'pages', …]    template variables = top-level keys of the data array
 * A data array is found at the calls ->view('tpl', 'heading', DATA) (admin modules), ->view->render('admin/…', DATA) and
 * $view->render('admin/…', DATA), and ->page('tpl', DATA) in the installer: literal arrays in that argument get their keys
 * renamed; a variable there is traced inside its function ($x = [...], $x += [...], $x['key']). A traced variable that also
 * goes into another call is refused – it might be a database row whose keys are column names.
 */
final class ViewRename
{
    /** @var list<string> */
    public array $problems = [];
    public int $count = 0;

    /** @param array{dirs?: list<string>, files?: array<string, string>, vars?: array<string, string>} $map */
    public function __construct(private readonly array $map)
    {
    }

    public function isTemplate(string $file): bool
    {
        foreach ($this->map['dirs'] ?? [] as $dir) {
            if (str_starts_with($file, $dir)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, string> template file moves */
    public function moves(string $root): array
    {
        $moves = [];
        foreach ($this->map['dirs'] ?? [] as $dir) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir, FilesystemIterator::SKIP_DOTS)) as $f) {
                $name = $f->getBasename('.php');
                if ($f->isFile() && isset($this->map['files'][$name])) {
                    $from = substr($f->getPathname(), strlen($root) + 1);
                    $moves[$from] = dirname($from) . '/' . $this->map['files'][$name] . '.php';
                    if (is_file($root . '/' . $moves[$from])) {
                        $this->problems[] = "template {$moves[$from]} already exists";
                    }
                }
            }
        }

        return $moves;
    }

    public function rewrite(string $code, string $file): string
    {
        $t = PhpToken::tokenize($code);
        $n = count($t);
        $repl = [];
        $vars = $this->map['vars'] ?? [];
        if ($this->isTemplate($file)) {
            $seen = [];
            foreach ($t as $i => $x) {
                if ($x->is(T_VARIABLE) && $x->text !== '$this') {
                    $old = substr($x->text, 1);
                    $new = $vars[$old] ?? $old;
                    if (isset($seen[$new]) && $seen[$new] !== $old) {
                        $this->problems[] = "$file: template variables „{$seen[$new]}“ and „{$old}“ would both become „{$new}“";
                    }
                    $seen[$new] = $old;
                    if ($new !== $old) {
                        $repl[$i] = '$' . $new;
                    }
                } elseif ($x->is(T_DOC_COMMENT)) {
                    $repl[$i] = (string) preg_replace_callback('/\$([A-Za-z_][A-Za-z0-9_]*)/', fn (array $m): string => '$' . ($vars[$m[1]] ?? $m[1]), $x->text);
                }
            }
        }
        for ($i = 0; $i < $n; $i++) {
            if (!$t[$i]->is(T_STRING) || !in_array($t[$i]->text, ['view', 'render', 'page'], true) || ($open = $this->next($t, $i)) === null || $t[$open]->text !== '(') {
                continue;
            }
            $prev = $this->prev($t, $i);
            if ($prev === null || !$t[$prev]->is(T_OBJECT_OPERATOR)) {
                continue;
            }
            $args = $this->arguments($t, $open);
            $template = $args[0] ?? null;
            $literal = $template !== null && count($template) === 1 && $t[$template[0]]->is(T_CONSTANT_ENCAPSED_STRING) ? substr($t[$template[0]]->text, 1, -1) : null;
            $obj = $this->prev($t, $prev);
            if ($t[$i]->text === 'view') {
                $dataArg = 2; // Module::view($template, $heading, $data)
            } elseif ($t[$i]->text === 'render' && $obj !== null && ($t[$obj]->text === 'view' || $t[$obj]->text === '$view')) {
                if ($literal === null || !preg_match('#^(admin|install)/#', $literal)) {
                    if ($literal !== null) {
                        continue; // a public template: its variables are the theme contract
                    }
                }
                $dataArg = 1;
            } elseif ($t[$i]->text === 'page' && str_ends_with($file, 'Install/Installer.php')) {
                $dataArg = 1;
            } else {
                continue;
            }
            if ($literal !== null) {
                $parts = explode('/', $literal);
                $last = array_pop($parts);
                if (isset($this->map['files'][$last]) && !str_starts_with($literal, 'admin/config/')) {
                    $repl[$template[0]] = $t[$template[0]]->text[0] . implode('/', [...$parts, $this->map['files'][$last]]) . $t[$template[0]]->text[0];
                    $this->count++;
                }
            }
            if (!isset($args[$dataArg])) {
                continue;
            }
            foreach ($this->arrayKeysIn($t, $args[$dataArg]) as $k) {
                $this->renameKey($t, $k, $repl);
            }
            $level = 0;
            foreach ($args[$dataArg] as $k) {
                if (in_array($t[$k]->text, ['(', '['], true)) {
                    $level++;
                } elseif (in_array($t[$k]->text, [')', ']'], true)) {
                    $level--;
                }
                if ($level === 0 && $t[$k]->is(T_VARIABLE) && ($after = $this->next($t, $k)) !== null && !in_array($t[$after]->text, ['->', '[', '?->', '::'], true)
                    && $t[$k]->text !== '$this' && !($t[$after]->is(T_OBJECT_OPERATOR) || $t[$after]->is(T_NULLSAFE_OBJECT_OPERATOR))) {
                    $this->trace($t, $i, $t[$k]->text, $file, $repl);
                }
            }
        }
        $out = '';
        foreach ($t as $i => $x) {
            $out .= $repl[$i] ?? $x->text;
        }

        return $out;
    }

    /** @param array<int, string> $repl */
    private function renameKey(array $t, int $k, array &$repl): void
    {
        $key = substr($t[$k]->text, 1, -1);
        if (isset($this->map['vars'][$key])) {
            $repl[$k] = $t[$k]->text[0] . $this->map['vars'][$key] . $t[$k]->text[0];
            $this->count++;
        }
    }

    /** Keys ('k' =>) of array literals directly in an argument expression. @param list<int> $arg @return list<int> */
    private function arrayKeysIn(array $t, array $arg): array
    {
        $keys = [];
        $depth = 0; // bracket depth within the argument; keys count at depth 1 of an array that starts at depth 0
        $paren = 0;
        foreach ($arg as $pos => $k) {
            $c = $t[$k]->text;
            if ($c === '(') {
                $paren++;
            } elseif ($c === ')') {
                $paren--;
            } elseif ($c === '[' && $paren === 0) {
                $depth++;
            } elseif ($c === ']' && $paren === 0) {
                $depth--;
            } elseif ($depth === 1 && $paren === 0 && $t[$k]->is(T_CONSTANT_ENCAPSED_STRING) && ($nx = $this->next($t, $k)) !== null && $t[$nx]->is(T_DOUBLE_ARROW)) {
                $keys[] = $k;
            }
        }

        return $keys;
    }

    /** Keys of a data variable inside the function that passes it to a template. @param array<int, string> $repl */
    private function trace(array $t, int $call, string $var, string $file, array &$repl): void
    {
        [$from, $to] = $this->functionRange($t, $call);
        for ($k = $from; $k <= $to; $k++) {
            if (!$t[$k]->is(T_VARIABLE) || $t[$k]->text !== $var) {
                continue;
            }
            $nx = $this->next($t, $k);
            if ($nx === null) {
                continue;
            }
            if ($t[$nx]->text === '[' && ($key = $this->next($t, $nx)) !== null && $t[$key]->is(T_CONSTANT_ENCAPSED_STRING)
                && ($close = $this->next($t, $key)) !== null && $t[$close]->text === ']') {
                $this->renameKey($t, $key, $repl); // $data['key']
            } elseif (in_array($t[$nx]->text, ['=', '+='], true) || $t[$nx]->is(T_PLUS_EQUAL)) {
                $end = $nx;
                $expr = [];
                for ($e = $nx + 1, $d = 0; $e <= $to; $e++) {
                    if (in_array($t[$e]->text, ['(', '['], true)) {
                        $d++;
                    } elseif (in_array($t[$e]->text, [')', ']'], true)) {
                        $d--;
                    } elseif ($t[$e]->text === ';' && $d === 0) {
                        break;
                    }
                    $expr[] = $e;
                }
                foreach ($this->arrayKeysIn($t, $expr) as $key) {
                    $this->renameKey($t, $key, $repl);
                }
            } else {
                // used as an argument of another call (not the template call itself): might be a database row
                $p = $this->prev($t, $k);
                if ($p !== null && in_array($t[$p]->text, ['(', ','], true) && !$this->insideCall($t, $k, $call)) {
                    $this->problems[] = "$file:{$t[$k]->line}: $var goes to a template and also into another call – check its keys by hand";
                }
            }
        }
    }

    private function insideCall(array $t, int $k, int $call): bool
    {
        $open = $this->next($t, $call);
        $close = $open;
        for ($d = 0, $j = $open; $j < count($t); $j++) {
            if ($t[$j]->text === '(') {
                $d++;
            } elseif ($t[$j]->text === ')' && --$d === 0) {
                $close = $j;
                break;
            }
        }

        return $k > $open && $k < $close;
    }

    /** @return array{0: int, 1: int} token range of the function around $i (the whole file for a script or template) */
    private function functionRange(array $t, int $i): array
    {
        $best = [0, count($t) - 1];
        foreach ($t as $f => $x) {
            if ($f >= $i) {
                break;
            }
            if (!$x->is([T_FUNCTION, T_FN])) {
                continue;
            }
            for ($b = $f; $b < count($t) && $t[$b]->text !== '{' && $t[$b]->text !== ';'; $b++);
            if (($t[$b]->text ?? '') !== '{') {
                continue;
            }
            for ($d = 0, $e = $b; $e < count($t); $e++) {
                if ($t[$e]->text === '{' || $t[$e]->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                    $d++;
                } elseif ($t[$e]->text === '}' && --$d === 0) {
                    break;
                }
            }
            if ($f < $i && $e > $i) {
                $best = [$f, $e]; // innermost wins: later starts come later in the loop
            }
        }

        return $best;
    }

    /** Token indexes of each top-level argument of the call that opens at $open. @return list<list<int>> */
    private function arguments(array $t, int $open): array
    {
        $args = [[]];
        for ($d = 0, $j = $open; $j < count($t); $j++) {
            $c = $t[$j]->text;
            if (in_array($c, ['(', '['], true) || $t[$j]->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES]) || $c === '{') {
                if ($d++ === 0) {
                    continue;
                }
            } elseif (in_array($c, [')', ']', '}'], true)) {
                if (--$d === 0) {
                    break;
                }
            } elseif ($c === ',' && $d === 1) {
                $args[] = [];
                continue;
            }
            if (!$t[$j]->isIgnorable()) {
                $args[count($args) - 1][] = $j;
            }
        }

        return $args;
    }

    private function prev(array $t, int $i): ?int
    {
        for ($j = $i - 1; $j >= 0; $j--) {
            if (!$t[$j]->isIgnorable()) {
                return $j;
            }
        }

        return null;
    }

    private function next(array $t, int $i): ?int
    {
        for ($j = $i + 1, $n = count($t); $j < $n; $j++) {
            if (!$t[$j]->isIgnorable()) {
                return $j;
            }
        }

        return null;
    }
}

/**
 * Declarations in one file: classes with their parent, interfaces and members.
 *
 * @return array<string, array{parent: ?string, interfaces: list<string>, methods: list<string>, consts: list<string>, props: list<string>, file: string}>
 */
function declarations(string $code, string $file): array
{
    $t = PhpToken::tokenize($code);
    $n = count($t);
    $ns = '';
    $imports = [];
    $classes = [];
    $current = null;
    $classDepth = -1;
    $depth = 0;
    $paren = 0;
    $fullName = function (string $name) use (&$ns, &$imports): string {
        if ($name[0] === '\\') {
            return substr($name, 1);
        }
        $first = str_contains($name, '\\') ? strstr($name, '\\', true) : $name;

        return isset($imports[$first]) ? $imports[$first] . substr($name, strlen($first)) : ltrim($ns . '\\' . $name, '\\');
    };
    $nextName = function (int $i) use ($t, $n): array {
        for ($j = $i + 1; $j < $n; $j++) {
            if (!$t[$j]->isIgnorable()) {
                return [$j, $t[$j]];
            }
        }

        return [$n, null];
    };
    for ($i = 0; $i < $n; $i++) {
        $x = $t[$i];
        if ($x->text === '{' || $x->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
            $depth++;
        } elseif ($x->text === '}') {
            $depth--;
            if ($current !== null && $depth === $classDepth) {
                $current = null;
            }
        }
        if ($x->text === '(') {
            $paren++;
        } elseif ($x->text === ')') {
            $paren--;
        }
        if ($x->is(T_NAMESPACE)) {
            [, $name] = $nextName($i);
            if ($name !== null && $name->is([T_STRING, T_NAME_QUALIFIED])) {
                $ns = $name->text;
            }
        } elseif ($x->is(T_USE) && $depth === 0 && ($nextName($i)[1]?->text ?? '') !== '(') {
            $stmt = '';
            for ($j = $i + 1; $j < $n && $t[$j]->text !== ';'; $j++) {
                $stmt .= $t[$j]->text;
            }
            $stmt = trim($stmt);
            if (!preg_match('/^(function|const)\s/i', $stmt) && !str_contains($stmt, '{')) {
                $alias = preg_match('/^(\S+)\s+as\s+(\w+)$/i', $stmt, $m) ? $m[2] : substr(strrchr('\\' . $stmt, '\\'), 1);
                $imports[$alias] = ltrim($m[1] ?? $stmt, '\\');
            }
        } elseif ($x->is([T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM]) && $current === null) {
            [$j, $name] = $nextName($i);
            $prevTok = null;
            for ($k = $i - 1; $k >= 0; $k--) {
                if (!$t[$k]->isIgnorable()) {
                    $prevTok = $t[$k];
                    break;
                }
            }
            if ($name === null || !$name->is(T_STRING) || ($prevTok !== null && $prevTok->is(T_DOUBLE_COLON))) {
                continue; // anonymous class or ::class
            }
            $fqcn = ltrim($ns . '\\' . $name->text, '\\');
            $classes[$fqcn] = ['parent' => null, 'interfaces' => [], 'methods' => [], 'consts' => [], 'props' => [], 'file' => $file];
            for ($k = $j + 1; $k < $n && $t[$k]->text !== '{'; $k++) {
                if ($t[$k]->is(T_EXTENDS)) {
                    [$k, $p] = $nextName($k);
                    $classes[$fqcn][$x->is(T_INTERFACE) ? 'interfaces' : 'parent'] = $x->is(T_INTERFACE) ? [$fullName($p->text)] : $fullName($p->text);
                } elseif ($t[$k]->is([T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_STRING]) && $k > $j && $classes[$fqcn]['parent'] !== $fullName($t[$k]->text)) {
                    $classes[$fqcn]['interfaces'][] = $fullName($t[$k]->text);
                }
            }
            $current = $fqcn;
            $classDepth = $depth;
        } elseif ($current !== null && $depth === $classDepth + 1) {
            if ($x->is(T_FUNCTION)) {
                [, $name] = $nextName($i);
                if ($name !== null && $name->is(T_STRING)) {
                    $classes[$current]['methods'][] = $name->text;
                }
            } elseif ($x->is(T_CONST)) {
                for ($j = $i + 1; $j < $n && $t[$j]->text !== '='; $j++) {
                    $last = $t[$j]->is(T_STRING) ? $t[$j]->text : ($last ?? null);
                }
                if (isset($last)) {
                    $classes[$current]['consts'][] = $last;
                    unset($last);
                }
            } elseif ($x->is(T_VARIABLE) && $paren === 0) {
                $classes[$current]['props'][] = substr($x->text, 1);
            }
        }
        if ($current !== null && $x->is(T_FUNCTION) && ($name = $nextName($i)[1]) !== null && $name->text === '__construct') {
            // promoted constructor properties
            for ($j = $i; $j < $n && $t[$j]->text !== '{' && $t[$j]->text !== ';'; $j++) {
                if ($t[$j]->is([T_PUBLIC, T_PROTECTED, T_PRIVATE, T_READONLY])) {
                    for ($k = $j; $k < $n && !$t[$k]->is(T_VARIABLE); $k++);
                    $classes[$current]['props'][] = substr($t[$k]->text, 1);
                    $j = $k;
                }
            }
        }
    }

    return $classes;
}

/** @return list<string> PHP files the tool works on, relative to $root */
function phpFiles(string $root): array
{
    $files = [];
    foreach (['system', 'layout', 'tools'] as $dir) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile() && str_ends_with($f->getFilename(), '.php') && !str_contains($f->getPathname(), '/tools/fixtures/') && $f->getPathname() !== __FILE__) {
                $files[] = substr($f->getPathname(), strlen($root) + 1);
            }
        }
    }
    foreach (glob($root . '/*.php') ?: [] as $f) {
        $files[] = basename($f);
    }
    sort($files);

    return $files;
}

function isView(string $file): bool
{
    return str_starts_with($file, 'system/views/') || str_starts_with($file, 'layout/');
}

function selfTest(): int
{
    $known = ['Kaleta\Stavitel\Prvek', 'Kaleta\Stavitel\Prvky\Nadpis', 'Kaleta\Stavitel\Kontext', 'Kaleta\Core\Db'];
    $map = [
        'namespaces' => ['Kaleta\Stavitel\Prvky' => 'Kaleta\Builder\Elements', 'Kaleta\Stavitel' => 'Kaleta\Builder'],
        'classes' => ['Kaleta\Stavitel\Prvek' => 'Element', 'Kaleta\Stavitel\Prvky\Nadpis' => 'Heading', 'Kaleta\Stavitel\Kontext' => 'Context'],
        'names' => ['vykresli' => 'render', 'nazev' => 'title', 'JEN_CASTI' => 'PARTS_ONLY', 'uroven' => 'level'],
    ];
    $cases = [
        // [source, expected, view?]
        ["<?php\nnamespace Kaleta\\Stavitel\\Prvky;\n\nuse Kaleta\\Stavitel\\Kontext;\nuse Kaleta\\Stavitel\\Prvek;\n\n/** @param Kontext \$k */\nfinal class Nadpis extends Prvek\n{\n    public const bool JEN_CASTI = false;\n    public static function vykresli(array \$p, Kontext \$k, string \$nazev = 'nazev'): string\n    {\n        return \$nazev . self::JEN_CASTI . \$k->nazev . \"{\$nazev}\";\n    }\n}\n",
         "<?php\nnamespace Kaleta\\Builder\\Elements;\n\nuse Kaleta\\Builder\\Context;\nuse Kaleta\\Builder\\Element;\n\n/** @param Context \$k */\nfinal class Heading extends Element\n{\n    public const bool PARTS_ONLY = false;\n    public static function render(array \$p, Context \$k, string \$title = 'nazev'): string\n    {\n        return \$title . self::PARTS_ONLY . \$k->title . \"{\$title}\";\n    }\n}\n", false],
        ["<?php\nnamespace Kaleta\\Stavitel;\n\nfinal class Stavba\n{\n    const PRVKY = [Prvky\\Nadpis::class, \\Kaleta\\Core\\Db::class];\n    public function x(): string { return Prvky\\Nadpis::vykresli(uroven: 1); }\n}\n",
         "<?php\nnamespace Kaleta\\Builder;\n\nfinal class Stavba\n{\n    const PRVKY = [Elements\\Heading::class, \\Kaleta\\Core\\Db::class];\n    public function x(): string { return Elements\\Heading::render(level: 1); }\n}\n", false],
        ["<?= e(\$nazev) ?><?= Kaleta\\Stavitel\\Prvky\\Nadpis::JEN_CASTI ?><?= \$k->nazev ?>",
         "<?= e(\$nazev) ?><?= Kaleta\\Builder\\Elements\\Heading::PARTS_ONLY ?><?= \$k->title ?>", true],
        ["<?php\nnamespace Kaleta\\Core;\n\nuse Kaleta\\Stavitel\\{Prvek, Kontext as K};\n\nfinal class Db { public function a(K \$k): Prvek { return new \\Kaleta\\Stavitel\\Prvky\\Nadpis(); } }\n",
         "<?php\nnamespace Kaleta\\Core;\n\nuse Kaleta\\Builder\\Element;\nuse Kaleta\\Builder\\Context as K;\n\nfinal class Db { public function a(K \$k): Element { return new \\Kaleta\\Builder\\Elements\\Heading(); } }\n", false],
    ];
    $cases[] = ["<?php\n\$f = glob('system/src/Stavitel/Prvky/*.php') + ['system/src/Stavitel/Kontext.php', 'system/src/Stavitel/Kontextove.php'];\n",
        "<?php\n\$f = glob('system/src/Builder/Elements/*.php') + ['system/src/Builder/Context.php', 'system/src/Builder/Kontextove.php'];\n", false];
    $fail = 0;
    foreach ($cases as $no => [$src, $expected, $view]) {
        $r = new Rename($map, $known);
        $got = $r->rewrite($src, "case$no", $view);
        if ($got !== $expected) {
            $fail++;
            echo "  FAIL case $no\n--- expected\n$expected\n--- got\n$got\n";
        }
    }
    $r = new Rename(['vars' => ['odpoved' => 'answer', 'pocet' => 'count']], []);
    $got = $r->rewrite("<?php\nfunction f(\$odpoved) { return \$odpoved . \$x->odpoved . g(odpoved: 1); }\nnew class { public \$pocet; };\n", 'v', false);
    if ($got !== "<?php\nfunction f(\$answer) { return \$answer . \$x->odpoved . g(answer: 1); }\nnew class { public \$pocet; };\n" || count($r->problems) !== 1) {
        $fail++;
        echo "  FAIL vars: $got " . json_encode($r->problems) . "\n";
    }
    $r = new Rename(['names' => ['chyba' => 'fail'], 'vars' => ['chyba' => 'error']], []);
    $got = $r->rewrite("<?php\nnamespace T;\nfinal class A { public function chyba(string \$chyba): void { \$this->chyba(chyba: \$chyba); } }\n", 'w', false);
    if ($got !== "<?php\nnamespace T;\nfinal class A { public function fail(string \$error): void { \$this->fail(error: \$error); } }\n") {
        $fail++;
        echo "  FAIL vars before names: $got\n";
    }
    $r = new Rename(['functions' => ['datum' => 'format_date'], 'names' => ['datum' => 'date']], []);
    $got = $r->rewrite("<?php\nfunction datum(\$v) { return \$v; }\nfinal class W { public static function datum(): string { return datum(1) . self::datum() . \$this->datum(); } }\n", 'f', false);
    if ($got !== "<?php\nfunction format_date(\$v) { return \$v; }\nfinal class W { public static function date(): string { return format_date(1) . self::date() . \$this->date(); } }\n") {
        $fail++;
        echo "  FAIL functions: $got\n";
    }
    $r = new Rename(['names' => ['sekce' => 'section']], []);
    $got = $r->rewrite("<?php\n\$m = new ReflectionMethod(A::class, 'sekce'); method_exists(\$a, 'sekce'); f('x', 'sekce');\n", 'r', false);
    if ($got !== "<?php\n\$m = new ReflectionMethod(A::class, 'section'); method_exists(\$a, 'section'); f('x', 'sekce');\n") {
        $fail++;
        echo "  FAIL reflection: $got\n";
    }
    $v = new ViewRename(['dirs' => ['system/views/admin/'], 'files' => ['vypis' => 'list'], 'vars' => ['stranky' => 'pages', 'nazev' => 'name']]);
    $src = "<?php\nfinal class M { function a() { \$data = ['stranky' => 1]; \$data['nazev'] = 2; return \$this->view('vypis', 'X', \$data + ['nazev' => 3]); }\n"
        . " function b(\$db) { return \$this->app->view->render('admin/vypis', ['stranky' => 1]) . \$this->app->view->render('vypis', ['stranky' => 1]) . \$db->insert('t', ['nazev' => 1]); } }\n";
    $want = "<?php\nfinal class M { function a() { \$data = ['pages' => 1]; \$data['name'] = 2; return \$this->view('list', 'X', \$data + ['name' => 3]); }\n"
        . " function b(\$db) { return \$this->app->view->render('admin/list', ['pages' => 1]) . \$this->app->view->render('vypis', ['stranky' => 1]) . \$db->insert('t', ['nazev' => 1]); } }\n";
    if (($got = $v->rewrite($src, 'system/src/M.php')) !== $want || $v->rewrite("<?= \$stranky . \$x ?>", 'system/views/admin/a.php') !== "<?= \$pages . \$x ?>") {
        $fail++;
        echo "  FAIL views: $got\n";
    }
    $v->rewrite("<?php\nfunction f(\$db) { \$row = ['nazev' => 1]; \$db->insert('t', \$row); return \$this->view('vypis', 'X', \$row); }\n", 'system/src/N.php');
    if (count($v->problems) !== 1) {
        $fail++;
        echo "  FAIL views problems: " . json_encode($v->problems) . "\n";
    }
    // collisions: two variables of one function, a member and an inherited one
    $r = new Rename(['names' => ['nazev' => 'name']], []);
    $r->variableCollisions("<?php\nnamespace T;\nfunction f(\$nazev) { \$name = 1; return fn () => \$name . \$nazev; }", 'v');
    $r->memberCollisions([
        'A' => ['parent' => null, 'interfaces' => [], 'methods' => ['name'], 'consts' => [], 'props' => [], 'file' => 'a'],
        'B' => ['parent' => 'A', 'interfaces' => [], 'methods' => ['nazev'], 'consts' => [], 'props' => [], 'file' => 'b'],
    ]);
    if (count($r->problems) !== 2) {
        $fail++;
        echo "  FAIL collisions: " . json_encode($r->problems) . "\n";
    }
    $d = declarations("<?php\nnamespace X;\nuse Y\\Z;\nfinal class A extends Z implements \\I {\n const K = 1;\n public function __construct(private int \$p) {}\n protected \$q;\n function m() { \$local = function () {}; }\n}\n", 'f');
    if (($d['X\A'] ?? null) !== ['parent' => 'Y\Z', 'interfaces' => ['I'], 'methods' => ['__construct', 'm'], 'consts' => ['K'], 'props' => ['p', 'q'], 'file' => 'f']) {
        $fail++;
        echo "  FAIL declarations: " . json_encode($d) . "\n";
    }
    echo $fail === 0 ? "rename self-test OK\n" : "rename self-test: $fail failed\n";

    return $fail === 0 ? 0 : 1;
}

// --- command line
$root = dirname(__DIR__);
$args = array_slice($argv, 1);
if (in_array('--self-test', $args, true)) {
    exit(selfTest());
}
$files = phpFiles($root);
$declared = [];
foreach ($files as $f) {
    $declared += declarations((string) file_get_contents($root . '/' . $f), $f);
}
if (in_array('--inventory', $args, true)) {
    $vars = [];
    foreach ($files as $f) {
        foreach (PhpToken::tokenize((string) file_get_contents($root . '/' . $f)) as $x) {
            if ($x->is(T_VARIABLE) && $x->text !== '$this') {
                $vars[substr($x->text, 1)][isView($f) ? 'view' : 'code'] = true;
            }
        }
    }
    $functions = [];
    foreach ($files as $f) {
        if (!str_starts_with($f, 'system/src/') || str_contains((string) file_get_contents($root . '/' . $f), "\nnamespace ")) {
            continue;
        }
        preg_match_all('/^function (\w+)/m', (string) file_get_contents($root . '/' . $f), $m);
        array_push($functions, ...$m[1]);
    }
    ksort($vars);
    echo json_encode(['classes' => $declared, 'functions' => $functions, 'variables' => array_map(fn (array $v): string => implode('+', array_keys($v)), $vars)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    exit(0);
}
$mapFile = $args[0] ?? '';
if ($mapFile === '' || !is_file($mapFile)) {
    exit("Usage: php tools/rename.php <map.php> [--apply] | --inventory | --self-test\n");
}
$apply = in_array('--apply', $args, true);
$map = require $mapFile;
$r = new Rename($map, array_keys($declared));
$only = $map['paths'] ?? [];

$changed = [];
foreach ($files as $f) {
    if ($only !== [] && array_filter($only, fn (string $p): bool => str_starts_with($f, $p)) === []) {
        // outside the renamed paths the same member or function name must not occur – it would be a different thing
        // that keeps its name while the calls to it inside the paths get renamed (Totp::over vs. a test helper over())
        foreach (PhpToken::tokenize((string) file_get_contents($root . '/' . $f)) as $x) {
            if ($x->is(T_STRING) && isset($map['names'][$x->text])) {
                $r->problems[] = "„{$x->text}“ is also used outside the renamed paths ($f:{$x->line}) – rename it with a new name there too, or only as a variable";
            }
        }
        continue;
    }
    $code = (string) file_get_contents($root . '/' . $f);
    if (!isView($f)) {
        $r->variableCollisions($code, $f);
    }
    $new = $r->rewrite($code, $f, isView($f));
    if ($new !== $code) {
        $changed[$f] = $new;
    }
}
$r->memberCollisions($declared);
$views = new ViewRename($map['views'] ?? []);
if (isset($map['views'])) {
    foreach ($files as $f) {
        $code = $changed[$f] ?? (string) file_get_contents($root . '/' . $f);
        if (($new = $views->rewrite($code, $f)) !== $code) {
            $changed[$f] = $new;
        }
    }
    $viewMoves = $views->moves($root);
    array_push($r->problems, ...$views->problems);
    $r->counts['views'] = $views->count;
}
foreach (['image', 'layout'] as $dir) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir, FilesystemIterator::SKIP_DOTS)) as $a) {
        if ($a->isFile() && preg_match('/\.(js|css)$/', $a->getFilename())) {
            $r->scanAsset((string) file_get_contents($a->getPathname()), substr($a->getPathname(), strlen($root) + 1));
        }
    }
}
foreach (glob($root . '/tools/*.sh') ?: [] as $sh) {
    $f = substr($sh, strlen($root) + 1);
    if ($only === [] || array_filter($only, fn (string $p): bool => str_starts_with($f, $p)) !== []) {
        $code = (string) file_get_contents($sh);
        if (($new = $r->rewriteShell($code)) !== $code) {
            $changed[$f] = $new;
        }
    }
}
$moves = $viewMoves ?? [];
foreach ($map['files'] ?? [] as $from => $to) {
    if (!is_file($root . '/' . $from)) {
        $r->problems[] = "file $from does not exist";
    }
    $moves[$from] = $to;
}
foreach ($r->classMap() as $old => $new) {
    $from = 'system/src/' . str_replace('\\', '/', substr($old, 7)) . '.php';
    $to = 'system/src/' . str_replace('\\', '/', substr($new, 7)) . '.php';
    if (str_starts_with($old, 'Kaleta\\') && str_starts_with($new, 'Kaleta\\') && is_file($root . '/' . $from)) {
        $moves[$from] = $to;
        if (is_file($root . '/' . $to) && !isset($moves[$to])) {
            $r->problems[] = "class file $to already exists";
        }
    }
}

echo count($changed) . " files change, " . count($moves) . " files move, " . array_sum($r->counts) . " references\n";
foreach ($r->stringHits as $name => $places) {
    $places = array_values(array_unique($places));
    echo "  review (string/JS/CSS) $name: " . implode(', ', array_slice($places, 0, 6)) . (count($places) > 6 ? ' … +' . (count($places) - 6) : '') . "\n";
}
$unused = array_diff(array_keys(($map['names'] ?? []) + ($map['vars'] ?? []) + ($map['functions'] ?? [])), array_keys($r->counts));
if ($unused !== []) {
    echo "  not found in code: " . implode(', ', $unused) . "\n";
}
if ($r->problems !== []) {
    echo "\nREFUSED – " . count($r->problems) . " problems:\n  " . implode("\n  ", array_unique($r->problems)) . "\n";
    exit(1);
}
if (!$apply) {
    echo "dry run – add --apply to write\n";
    exit(0);
}
foreach ($changed as $f => $code) {
    file_put_contents($root . '/' . $f, $code);
}
foreach ($moves as $from => $to) {
    @mkdir(dirname($root . '/' . $to), 0775, true);
    exec('cd ' . escapeshellarg($root) . ' && git mv ' . escapeshellarg($from) . ' ' . escapeshellarg($to), $o, $code);
    if ($code !== 0) {
        echo "git mv $from failed\n";
        exit(1);
    }
    @rmdir(dirname($root . '/' . $from));
}
// old class names keep working through the autoloader (system/class-aliases.php); an older alias follows a second rename
$aliasFile = $root . '/system/class-aliases.php';
$aliases = array_map(fn (string $to): string => $r->classMap()[$to] ?? $to, (array) require $aliasFile) + $r->classMap();
ksort($aliases);
$source = (string) file_get_contents($aliasFile);
file_put_contents($aliasFile, substr($source, 0, (int) strpos($source, "return [")) . "return [\n"
    . implode('', array_map(fn (string $old, string $new): string => '    ' . var_export($old, true) . ' => ' . var_export($new, true) . ",\n", array_keys($aliases), $aliases)) . "];\n");
$bad = 0;
foreach (array_unique([...array_keys($changed), ...array_values($moves)]) as $f) {
    $f = $moves[$f] ?? $f;
    if (!str_ends_with($f, '.php')) {
        continue;
    }
    exec('php -l ' . escapeshellarg($root . '/' . $f) . ' 2>&1', $o, $code);
    if ($code !== 0) {
        echo "syntax error after rename: $f\n";
        $bad++;
    }
}
echo $bad === 0 ? "applied\n" : "applied with $bad syntax errors\n";
exit($bad === 0 ? 0 : 1);
