#!~/projects/restart/.venv/bin/python

import subprocess
import sys
from pathlib import Path

BASE_DIR = Path(__file__).parent


def main(filename: str):
    file = BASE_DIR / filename
    with open(file, "r") as f:
        content = f.readlines()
        for line in content:
            parts = line.split("|")
            title = parts[0].strip()
            body = parts[1].strip()
            res = subprocess.run(
                [
                    "gh",
                    "issue",
                    "create",
                    "--assignee",
                    "@me",
                    "--title",
                    title,
                    "--body",
                    body,
                ]
            )
            print(res)


if __name__ == "__main__":
    args = sys.argv[1:]
    if args:
        main(args[0])
    else:
        user_file = input("Enter the filename (default: issues.txt): ")
        if not user_file:
            user_file = "issues.txt"
        main(user_file)
