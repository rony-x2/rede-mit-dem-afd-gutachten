#!/bin/bash
# gewichte.sh TEXT VOLLTEXT – Gewichte in mcp_server.php setzen (nur zum Abstimmen)
cd ~/poc/afd || exit 1
sed -i "s/^const AFD_GEWICHT_TEXT   = .*;/const AFD_GEWICHT_TEXT   = $1;/; s/^const AFD_GEWICHT_VOLLTEXT = .*;/const AFD_GEWICHT_VOLLTEXT = $2;/" mcp_server.php
php -l mcp_server.php > /dev/null && grep -E "^const AFD_GEWICHT_(TEXT|VOLLTEXT)" mcp_server.php | tr '\n' ' '; echo
