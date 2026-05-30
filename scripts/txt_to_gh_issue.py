#!~/projects/restart/.venv/bin/python

import subprocess
import sys
from pathlib import Path
from typing import Union

BASE_DIR = Path(__file__).parent

def create_issue(title: str, body: str):
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

def main(source: Union[str, list[str]]):
    if isinstance(source, list):
        title = source[0]
        body = source[1]
        create_issue(title, body)

    else:
        filename = source
        file = BASE_DIR / filename
        with open(file, "r") as f:
            content = f.readlines()
            for line in content:
                parts = line.split("|")
                title = parts[0].strip()
                body = parts[1].strip()
                create_issue(title, body)


if __name__ == "__main__":
    # print(sys.argv)
    args = sys.argv[1:]
    if args:
        main(args)
    else:
        user_file = input("Enter the filename (default: issues.txt): ")
        if not user_file:
            user_file = "issues.txt"
        main(user_file)
