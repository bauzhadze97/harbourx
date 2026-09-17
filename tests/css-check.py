import glob, re, sys

RESERVED = {
    'none', 'inherit', 'initial', 'unset', 'revert', 'normal', 'infinite',
    'alternate', 'alternate-reverse', 'reverse', 'backwards', 'forwards',
    'both', 'paused', 'running', 'linear', 'ease', 'ease-in', 'ease-out',
    'ease-in-out', 'step-start', 'step-end',
}

def strip_functions(value):
    """Drop var()/cubic-bezier()/steps() groups: their inner commas and words
    are arguments, not animation names."""
    out, depth = [], 0
    i = 0
    while i < len(value):
        ch = value[i]
        if ch == '(':
            depth += 1
        elif ch == ')':
            depth = max(0, depth - 1)
            i += 1
            continue
        if depth == 0:
            out.append(ch)
        i += 1
    # remove the function identifier left behind, e.g. "var" or "cubic-bezier"
    return re.sub(r'\b(var|cubic-bezier|steps|linear)\b', ' ', ''.join(out))

def names(value):
    found = []
    for part in strip_functions(value).split(','):
        for word in part.split():
            if re.match(r'^-?[A-Za-z_][\w-]*$', word) and word not in RESERVED:
                found.append(word)
                break          # the name is the first identifier in a shorthand
    return found

fail = False
keyframes, used = set(), []

def scan(path, css):
    keyframes.update(re.findall(r'@keyframes\s+([\w-]+)', css))
    for value in re.findall(r'animation(?:-name)?\s*:\s*([^;}]+)', css):
        for name in names(value):
            used.append((path, name))

for path in sorted(glob.glob('*.css')):
    css = open(path, encoding='utf-8').read()
    if css.count('{') != css.count('}'):
        print(f'{path}: unbalanced braces ({css.count("{")} open, {css.count("}")} close)')
        fail = True
    scan(path, css)

for path in sorted(glob.glob('*.html') + glob.glob('*.php')):
    text = open(path, encoding='utf-8', errors='replace').read()
    for block in re.findall(r'(?s)<style>(.*?)</style>', text):
        scan(path, block)

for path, name in used:
    if name not in keyframes:
        print(f'{path}: animation references undefined keyframe "{name}"')
        fail = True

print(f'{len(keyframes)} keyframes defined, {len(used)} animation references checked')
sys.exit(1 if fail else 0)
