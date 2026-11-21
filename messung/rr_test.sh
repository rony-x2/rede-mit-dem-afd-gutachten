#!/bin/bash
# rr_test.sh – Reranker Qwen3-Reranker-0.6B ohne andere aktive Modelle messen: CPU, dann P40.
cd ~/afd-gutachten || exit 1
M=~/modelle/Qwen3-Reranker-0.6B-Q8_0.gguf
lauf() {   # $1 = Name, $2 = -ngl
  echo "$(date +%T) Reranker $1 starten"
  ~/llama-server/cuda/llama-server -m $M --reranking --pooling rank --embedding -ngl $2 -t 6 -np 1 \
    -c 16384 -ub 8192 -b 8192 --host 127.0.0.1 --port 1241 > rerank_$1.log 2>&1 &
  RPID=$!
  for i in $(seq 1 60); do curl -s 127.0.0.1:1241/health | grep -q ok && break; sleep 2; done
  ./venv/bin/python messen.py --varianten vek+rr,fq+rr --rerank http://127.0.0.1:1241 --rr_n 20 --rr_zeichen 1000 --aus rr_$1_20.json
  ./venv/bin/python messen.py --varianten fq+rr --rerank http://127.0.0.1:1241 --rr_n 40 --rr_zeichen 2000 --aus rr_$1_40.json
  kill $RPID; sleep 3
}
lauf cpu 0
lauf p40 99
echo "$(date +%T) fertig"
