#!/usr/bin/env python3
import sys
import re

def strip_builders(filepath, outpath):
    with open(filepath, 'r') as f:
        content = f.read()

    # Remove the heavy builders that are not needed for maratona/minimal profile.
    # Note: we use regex to match blocks that start with FROM and end before the next FROM or a major section.

    # 1. Remove portugol-studio-builder
    content = re.sub(r'FROM eclipse-temurin:11-jdk AS portugol-studio-builder.*?((?=FROM )|(?=# MicroHelium))', '', content, flags=re.DOTALL)
    
    # 2. Remove scratch-run-builder
    content = re.sub(r'FROM node:\d+-alpine AS scratch-run-builder.*?((?=FROM )|(?=# MicroHelium))', '', content, flags=re.DOTALL)

    # 3. Remove gprolog-builder
    content = re.sub(r'FROM php:\d+\.\d+-.*?-alpine AS gprolog-builder.*?((?=FROM )|(?=# MicroHelium))', '', content, flags=re.DOTALL)

    # 4. Remove swipl-builder
    content = re.sub(r'FROM php:\d+\.\d+-.*?-alpine AS swipl-builder.*?((?=FROM )|(?=# MicroHelium))', '', content, flags=re.DOTALL)

    # 5. Remove gportugol-builder
    content = re.sub(r'FROM php:\d+\.\d+-.*?-alpine AS gportugol-builder.*?((?=FROM )|(?=# MicroHelium))', '', content, flags=re.DOTALL)

    # Now remove the COPY --from lines for these builders
    content = re.sub(r'COPY --from=portugol-studio-builder[^\n]*\n', '', content)
    content = re.sub(r'COPY --from=scratch-run-builder[^\n]*\n', '', content)
    content = re.sub(r'COPY --from=gprolog-builder[^\n]*\n', '', content)
    content = re.sub(r'COPY --from=swipl-builder[^\n]*\n', '', content)
    content = re.sub(r'COPY --from=gportugol-builder[^\n]*\n', '', content)

    # Optionally, we could remove the RUN commands that set them up, but just removing COPY breaks the buildkit dependency tree,
    # which is the main goal. To prevent the RUN from failing because the files are missing, we should comment them out or remove them.
    # We will remove blocks between # SWI-Prolog and the next blank line or major comment.
    content = re.sub(r'# SWI-Prolog \(issue #305, Lote D\).*?rm -f /tmp/fumaca\.pl\n', '', content, flags=re.DOTALL)
    content = re.sub(r'# GNU Prolog \(issue #305, Lote D\).*?rm -rf /tmp/fumaca-gprolog\n', '', content, flags=re.DOTALL)
    content = re.sub(r'# G-Portugol \(issue #296\).*?rm -rf /tmp/fumaca-gportugol\n', '', content, flags=re.DOTALL)
    content = re.sub(r'# Scratch \(issue #268\).*?chmod 755 /usr/local/bin/scratch-run.*?\n', '', content, flags=re.DOTALL)
    content = re.sub(r'# Portugol Studio \(issue #269.*?\).*?rm -rf /tmp/fumaca-portugol\n', '', content, flags=re.DOTALL)

    # For dev without headers:
    content = re.sub(r'RUN set -eux; \\\n\s+printf \'#!/bin/sh\\nexec /opt/swipl/bin/swipl.*?\n', '', content, flags=re.DOTALL)
    content = re.sub(r'RUN set -eux; \\\n\s+printf \'#!/bin/sh\\nPATH="/opt/gprolog/bin.*?rm -rf /tmp/fumaca-gprolog\n', '', content, flags=re.DOTALL)
    
    with open(outpath, 'w') as f:
        f.write(content)

if __name__ == '__main__':
    strip_builders('Dockerfile', 'Dockerfile.slim')
    strip_builders('Dockerfile.dev', 'Dockerfile.dev.slim')
    strip_builders('Dockerfile.judge', 'Dockerfile.judge.slim')
