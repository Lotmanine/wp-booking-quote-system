#!/usr/bin/env python3
"""Samples docker stats for the bookingdemo containers and prints the peak per service.

Usage: python scripts/sample-memory.py [seconds]
"""
import re
import subprocess
import sys
import time

UNITS = {'B': 1 / 1048576, 'KiB': 1 / 1024, 'MiB': 1, 'GiB': 1024}
duration = int(sys.argv[1]) if len(sys.argv) > 1 else 60
peak, last = {}, {}
end = time.time() + duration
samples = 0

while time.time() < end:
    out = subprocess.run(['docker', 'stats', '--no-stream', '--format', '{{.Name}} {{.MemUsage}}'],
                         capture_output=True, text=True).stdout
    samples += 1
    for line in out.splitlines():
        match = re.match(r'bookingdemo-(\S+?)-1 ([\d.]+)(B|KiB|MiB|GiB) / ([\d.]+)(B|KiB|MiB|GiB)', line)
        if match:
            used = float(match[2]) * UNITS[match[3]]
            limit = float(match[4]) * UNITS[match[5]]
            peak[match[1]] = max(peak.get(match[1], 0), used)
            last[match[1]] = (used, limit)

print(f'{samples} samples over {duration} seconds')
total = 0
for name in sorted(peak):
    if name == 'n8n':
        print(f'{name:18} peak {peak[name]:7.1f} MiB  (local only, not deployed)')
        continue
    total += peak[name]
    print(f'{name:18} peak {peak[name]:7.1f} MiB  limit {last[name][1]:7.1f} MiB')
print(f'{"stack total":18} peak {total:7.1f} MiB  (sum of per-service peaks, without local n8n)')
