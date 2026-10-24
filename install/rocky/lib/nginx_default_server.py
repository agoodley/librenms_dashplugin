"""Remove the server blocks of nginx.conf's http block (the default site), as LibreNMS' documentation says to.

    python3 nginx_default_server.py [--check] /etc/nginx/nginx.conf

--check only answers (exit code 0: there is one to remove, 1: there is none). Comments and strings are respected;
commented-out blocks (the TLS example) stay as they are.
"""
import sys


def blocks(text):
    """The (start, end) offsets of each server block directly inside the http block."""
    found, depth, i, word_start, http_depth = [], 0, 0, None, None
    stack = []  # the names of the open blocks
    word = ''
    n = len(text)
    while i < n:
        c = text[i]
        if c == '#':
            while i < n and text[i] != '\n':
                i += 1
            continue
        if c in '"\'':
            q = c
            i += 1
            while i < n and text[i] != q:
                i += 2 if text[i] == '\\' else 1
            i += 1
            continue
        if c.isalnum() or c in '_-':
            if not word:
                word_start = i
            word += c
            i += 1
            continue
        if c == '{':
            name = word or ''
            stack.append((name, word_start if word else i))
            word = ''
            i += 1
            continue
        if c == '}':
            name, start = stack.pop()
            if name == 'server' and [s[0] for s in stack] == ['http']:
                found.append((start, i + 1))
            word = ''
            i += 1
            continue
        if c == ';':
            word = ''
            i += 1
            continue
        if not c.isspace():
            word = ''
        elif word:
            # a directive's name is the first word: keep it until { or ;
            j = i
            while j < n and text[j].isspace():
                j += 1
            if j < n and text[j] == '{':
                i = j
                continue
            word = ''
        i += 1
    return found


def main():
    args = sys.argv[1:]
    check = args and args[0] == '--check'
    path = args[-1]
    with open(path, encoding='utf-8') as f:
        text = f.read()
    found = blocks(text)
    if check:
        sys.exit(0 if found else 1)
    for start, end in reversed(found):
        # the block's own line, and the empty line after it
        line_start = text.rfind('\n', 0, start) + 1
        if text[line_start:start].strip() == '':
            start = line_start
        while end < len(text) and text[end] in ' \t':
            end += 1
        if end < len(text) and text[end] == '\n':
            end += 1
        text = text[:start] + text[end:]
    with open(path + '.new', 'w', encoding='utf-8') as f:
        f.write(text)
    import os
    st = os.stat(path)
    os.chmod(path + '.new', st.st_mode & 0o7777)
    os.replace(path + '.new', path)


if __name__ == '__main__':
    main()
