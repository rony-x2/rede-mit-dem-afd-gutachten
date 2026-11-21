#!/bin/bash
# nachlauf.sh – nach der Anreicherung: Zusätze auf der P40 einbetten und alle Varianten messen.
# Läuft auf der lm-vm im Hintergrund; Protokoll in nachlauf.log.
cd ~/afd-gutachten || exit 1
echo "$(date +%T) warte auf das Ende der Anreicherung"
while pgrep -f '[a]nreichern.py' > /dev/null; do sleep 30; done
tail -3 anreichern.log
./venv/bin/python anreichern.py --nur_dateien
./venv/bin/python fragen_datei.py

echo "$(date +%T) Gemma auf der P40 entladen, Einbettungsmodell auf der GPU starten (Port 1242)"
curl -s -X POST 127.0.0.1:1235/models/unload -H 'Content-Type: application/json' -d '{"model":"google/gemma-4-26b-a4b"}'; echo
sleep 5
~/llama-server/cuda/llama-server -m ~/modelle/Qwen3-Embedding-0.6B-Q8_0.gguf --embedding --pooling last \
  -ngl 99 -np 4 -c 32768 -ub 4096 -b 4096 --host 127.0.0.1 --port 1242 > einbettung_gpu.log 2>&1 &
EPID=$!
for i in $(seq 1 60); do curl -s 127.0.0.1:1242/health | grep -q ok && break; sleep 2; done

for x in anreicherung kombi fragen; do
  rm -f ${x}_vektoren.jsonl
  python3 einbetten.py --url http://127.0.0.1:1242/v1 --stapel 64 --ein ${x}_einbettung.jsonl --aus ${x}_vektoren.jsonl 2>&1 | tail -1
done
kill $EPID

echo "$(date +%T) Messung"
./venv/bin/python messen.py --varianten live,vek,anr,kombi,max,fq,vek\|anr,vek\|fq,vek\|fq\|anr --aus vergleich.json
echo "$(date +%T) fertig"
