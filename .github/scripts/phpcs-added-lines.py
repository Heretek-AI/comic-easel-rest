#!/usr/bin/env python3
"""
Report PHPCS findings that touch lines a pull request actually adds.

Reads:
  - /tmp/phpcs.json: phpcs --report=json output
  - /tmp/pr.diff:    git diff --unified=0 output, restricted to *.php

Prints only findings whose line number falls inside an added line in the
diff. Exits 0 even if findings are reported (advisory, not a gate).
"""

import json
import sys
from pathlib import Path

if len(sys.argv) != 3:
    sys.exit("usage: phpcs-added-lines.py <phpcs.json> <pr.diff>")

phpcs_json_path = Path(sys.argv[1])
diff_path = Path(sys.argv[2])

# Parse the diff: for each file, build a set of added line numbers in the
# post-image (the file as it will look after the PR is merged).
added_lines = {}  # file path -> {line_numbers}
current_file = None
current_added = None

for raw in diff_path.read_text().splitlines():
    if raw.startswith("+++ "):
        # "+++ b/path/to/file"
        path = raw[6:].strip()
        if path == "/dev/null":
            current_file = None
            continue
        current_file = path
        current_added = set()
        added_lines[current_file] = current_added
        continue
    if raw.startswith("@@"):
        # "@@ -old_start,old_count +new_start,new_count @@"
        # We want the + side.
        plus = raw.split("+", 1)[1].split(" ", 1)[0]
        if "," in plus:
            start, count = plus.split(",")
        else:
            start, count = plus, "1"
        start = int(start)
        count = int(count)
        current_hunk_added = set(range(start, start + count))
        if current_file is not None:
            added_lines[current_file].update(current_hunk_added)
        continue

# Parse the phpcs JSON.
try:
    payload = json.loads(phpcs_json_path.read_text())
except FileNotFoundError:
    sys.exit("phpcs.json not found")
except json.JSONDecodeError:
    sys.exit("phpcs.json is not valid JSON")

reports = payload.get("files", {})
total_reported = 0
for filename, file_data in reports.items():
    file_added = added_lines.get(filename, set())
    file_findings = []
    for finding in file_data.get("messages", []):
        line = finding.get("line", 0)
        if line in file_added:
            file_findings.append(finding)
            total_reported += 1
    if file_findings:
        print(f"--- {filename}")
        for f in file_findings:
            print(f"  L{f['line']:>4} {f['level']}  {f['source']}: {f['message']}")

print()
if total_reported:
    print(f"{total_reported} added-line PHPCS finding(s) reported. Advisory.")
    sys.exit(0)
else:
    print("No added-line PHPCS findings.")
    sys.exit(0)