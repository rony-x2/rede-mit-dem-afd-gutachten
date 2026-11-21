#!/bin/bash
m(){ curl -s -X POST https://poc.deutschkaemer.info/afd-gutachten/mcp -H "Content-Type: application/json" -H "Accept: application/json, text/event-stream" -d "$1"; }
for f in "Wer hat das Gutachten bezahlt?" "Wer steckt hinter dem Gutachten?" "Wer hat das Gutachten geschrieben?" "Wie finanziert sich die GFF?" "Ist die GFF unabhängig?" "Wie hat die GFF KI eingesetzt?" "Will die AfD Ausländer ausweisen?"; do
  m "{\"jsonrpc\":\"2.0\",\"id\":2,\"method\":\"tools/call\",\"params\":{\"name\":\"bedeutung_suchen\",\"arguments\":{\"frage\":\"$f\",\"anzahl\":3}}}" \
  | php -r '$j=json_decode(stream_get_contents(STDIN),true); $d=json_decode($j["result"]["content"][0]["text"],true); echo $d["frage"]," -> GFF: ", implode("; ", array_map(fn($e)=>$e["titel"]." (".$e["naehe"].")", $d["herausgeberin"]["eintraege"] ?? [])), " | bester Treffer: ", $d["treffer"][0]["abschnitt"] ?? "-", " (", $d["treffer"][0]["naehe"] ?? "", ")\n";'
done
