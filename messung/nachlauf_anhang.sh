#!/bin/bash
# nachlauf_anhang.sh – nach der Anreicherung des Anhangs: Ausfälle nachholen, Fragen auf der P40 einbetten.
cd ~/afd-gutachten || exit 1
echo "$(date +%T) warte auf das Ende des ersten Durchlaufs"
while pgrep -f '[a]nreichern.py' > /dev/null; do sleep 30; done
grep "^fertig" anreichern_anhang.log
echo "$(date +%T) zweiter Durchlauf für die Ausfälle (mit Ausweg ohne JSON-Vorgabe)"
./venv/bin/python anreichern.py --bereich anhang --backend http://127.0.0.1:1235,google/gemma-4-26b-a4b,4 \
  --backend http://127.0.0.1:1236,google/gemma-4-26b-a4b,8 2>&1 | grep -v "^Fehler" | tail -3
./venv/bin/python fragen_datei.py
echo "$(date +%T) Gemma auf der P40 entladen, Einbettungsmodell auf der GPU (Port 1242)"
curl -s -X POST 127.0.0.1:1235/models/unload -H 'Content-Type: application/json' -d '{"model":"google/gemma-4-26b-a4b"}'; echo
sleep 5
~/llama-server/cuda/llama-server -m ~/modelle/Qwen3-Embedding-0.6B-Q8_0.gguf --embedding --pooling last \
  -ngl 99 -np 4 -c 32768 -ub 4096 -b 4096 --host 127.0.0.1 --port 1242 > einbettung_gpu.log 2>&1 &
EPID=$!
for i in $(seq 1 60); do curl -s 127.0.0.1:1242/health | grep -q ok && break; sleep 2; done
# einbetten.py überspringt, was schon in fragen_vektoren.jsonl steht (Hauptteil) – rechnet also nur den Anhang
python3 einbetten.py --url http://127.0.0.1:1242/v1 --stapel 64 --ein fragen_einbettung.jsonl --aus fragen_vektoren.jsonl 2>&1 | tail -1
kill $EPID
wc -l fragen_vektoren.jsonl
echo "$(date +%T) fertig"
