# Familie „Rede mit …“: Sprechblase = Chat mit einer Quelle; Zeichen innen = Projekt.
# Andere PoC-Arten (z. B. Nachrichtenseite): abgerundetes Quadrat statt Blase.
BLASE = 'M7.5 3.5h9a4 4 0 0 1 4 4v6a4 4 0 0 1-4 4h-5.5l-4.5 3.5v-3.5a4 4 0 0 1-4-4v-6a4 4 0 0 1 4-4z'
QUADRAT = 'M8 3.5h8a4.5 4.5 0 0 1 4.5 4.5v8a4.5 4.5 0 0 1-4.5 4.5H8A4.5 4.5 0 0 1 3.5 16V8A4.5 4.5 0 0 1 8 3.5z'
INNEN = {
  # „21“ für Art. 21 GG (Parteien, Parteiverbot)
  'g21': 'M8.4 8.4C8.6 7.4 9.4 6.7 10.3 6.7C11.3 6.7 12 7.4 12 8.3C12 9.3 11.2 10 10.4 10.8L8.3 13.6H12.2M14.2 8.1L16 6.7V13.6',
  # Waage für Urteile
  'waage': 'M12 6.3v7.4M9.6 13.7h4.8M7.6 8h8.8M7.6 8L6.2 11.2M7.6 8L9 11.2M6.2 11.2a1.4 1.4 0 0 0 2.8 0M16.4 8L15 11.2M16.4 8L17.8 11.2M15 11.2a1.4 1.4 0 0 0 2.8 0',
  # aufgeschlagenes Buch für das Grundgesetz
  'buch': 'M12 8.2c-1.3-1-3-1.4-4.6-1.2v6.2c1.6-.2 3.3.2 4.6 1.2c1.3-1 3-1.4 4.6-1.2V7c-1.6-.2-3.3.2-4.6 1.2zM12 8.2v6.2',
  # Zeitung (ohne Blase)
  'zeitung': 'M7.5 7.5h6v3.5h-6zM15.5 7.5h1.5M15.5 10h1.5M7.5 13.5h9.5M7.5 16h9.5',
}
def linie(rahmen, innen, sw=1.6):
    return (f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="{sw}" '
            f'stroke-linecap="round" stroke-linejoin="round"><path d="{rahmen}"/><path d="{innen}"/></svg>')
def kachel(rahmen, innen, farbe):
    return (f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><rect width="24" height="24" rx="5.5" fill="{farbe}"/>'
            f'<g fill="none" stroke="#fff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" transform="translate(1.2 1) scale(.9)">'
            f'<path d="{rahmen}"/><path d="{innen}"/></g></svg>')
PROJ = [('afd', 'Rede mit dem AfD-Gutachten', BLASE, 'g21', '#2f8a7b'),
        ('gg', 'Rede mit dem Grundgesetz (Platzhalter)', BLASE, 'buch', '#5b4fc4'),
        ('urteile', 'Rede mit Urteilen (Platzhalter)', BLASE, 'waage', '#b5523b'),
        ('news', 'Nachrichtenseite (Platzhalter, kein Chat)', QUADRAT, 'zeitung', '#3f5f80')]
for k, t, r, i, f in PROJ:
    open(f'fam_{k}_linie.svg', 'w').write(linie(r, INNEN[i]))
    open(f'fam_{k}_kachel.svg', 'w').write(kachel(r, INNEN[i], f))
