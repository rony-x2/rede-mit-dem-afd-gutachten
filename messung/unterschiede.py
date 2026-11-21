import json
d = json.load(open('vergleich.json', encoding='utf-8'))
L = {z['f']: z for z in d['live']['zeilen']}
F = {z['f']: z for z in d['fq']['zeilen']}
for f in L:
    a, b = L[f]['streng'], F[f]['streng']
    if a != b or b is None or b > 3:
        print(f"{str(a):>4} -> {str(b):>4}  {f[:72]}  | fq: {F[f]['top3'][:2]}")
