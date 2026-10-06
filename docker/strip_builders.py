import sys

def strip_builders(filepath, outpath):
    with open(filepath, 'r') as f:
        lines = f.readlines()

    out = []
    i = 0
    while i < len(lines):
        line = lines[i]
        
        # Strip the builder stages at the top
        if line.startswith("FROM ") and any(x in line for x in [
            "AS portugol-studio-builder", "AS scratch-run-builder", 
            "AS gprolog-builder", "AS swipl-builder", "AS gportugol-builder"]):
            while i < len(lines):
                i += 1
                if i < len(lines) and (lines[i].startswith("FROM ") or lines[i].startswith("# MicroHelium") or lines[i].startswith("# ----------------")):
                    break
            continue
            
        # Strip the COPY lines
        if line.startswith("COPY --from=") and any(x in line for x in [
            "portugol-studio-builder", "scratch-run-builder", 
            "gprolog-builder", "swipl-builder", "gportugol-builder"]):
            i += 1
            continue

        # Buffer and check RUN lines
        if line.startswith("RUN "):
            run_block = [line]
            j = i
            while j < len(lines) and lines[j].rstrip().endswith("\\"):
                j += 1
                if j < len(lines):
                    run_block.append(lines[j])
            
            full_run = "".join(run_block)
            if any(k in full_run for k in [
                "scratch-run/index.js", 
                "/opt/swipl/bin/swipl", 
                "/opt/gprolog/bin/gplc", 
                "fumaca-gportugol", 
                "ExecutaPortugol"
            ]):
                i = j + 1
                continue

        out.append(line)
        i += 1

    with open(outpath, 'w') as f:
        f.writelines(out)

if __name__ == '__main__':
    strip_builders('Dockerfile', 'Dockerfile.slim')
    strip_builders('Dockerfile.dev', 'Dockerfile.dev.slim')
    strip_builders('Dockerfile.judge', 'Dockerfile.judge.slim')
