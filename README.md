# MicroHelium

A comprehensive **Hackathon and Programming Contest Management Platform** built with Laravel 13, inspired by the BOCA Online Contest Administrator. MicroHelium provides a modern, feature-rich environment for organizing competitive programming events, hackathons, and CTF competitions.

## Features

### Contest Management
- **Multi-Contest Support**: Run multiple contests simultaneously with independent configurations
- **Flexible Timing**: Configure contest duration, start/end times, and freeze periods
- **Multi-Site Architecture**: Support for distributed contests across multiple physical locations
- **Penalty System**: Configurable penalty times for incorrect submissions

### Problem Management
- **Problem Packages**: Import problems as ZIP files with standardized structure
- **Multi-Language Support**: C, C++, Java, Python, Kotlin, and more
- **Auto-Judge Integration**: Automatic compilation, execution, and output comparison
- **Test Cases**: Multiple input/output test case pairs per problem
- **Problem Colors**: Visual identification with balloon colors for solved problems

### Team & User Management
- **Role-Based Access**: Admin, Judge, Staff, Team, and Spectator roles
- **Team Registration**: Support for individual or team-based participation
- **IP Restrictions**: Optional IP-based login restrictions for security
- **Multi-Login Control**: Configure simultaneous login policies

### Submission System
- **Real-Time Judging**: Immediate feedback on code submissions
- **Verdict Types**: Accepted, Wrong Answer, Time Limit, Runtime Error, Compilation Error
- **Duplicate Detection**: SHA-based detection of identical submissions
- **Source Code Management**: Download and review all submissions

### Scoring & Leaderboard
- **Real-Time Scoreboard**: Live updates with configurable freeze time
- **ICPC-Style Scoring**: Problems solved + time penalty ranking
- **Score Export**: ICPC format, JSON, and custom report exports
- **Balloon Notifications**: Visual indicators for solved problems

### Clarification System
- **Q&A Communication**: Teams can ask judges questions about problems
- **Broadcast Clarifications**: Judges can send announcements to all teams
- **Status Tracking**: Track pending, answered, and broadcast clarifications

### Additional Features
- **File Backup System**: Teams can backup their work during the contest
- **Task Management**: Staff task assignment (printing, balloon delivery)
- **Comprehensive Logging**: Full audit trail of all actions
- **Report Generation**: Statistics, charts, and analytics

## Requirements

- **PHP**: >= 8.3
- **Laravel**: 12.x
- **Database**: MySQL 8.0+ / PostgreSQL 14+ / SQLite
- **Node.js**: >= 20.x
- **Composer**: >= 2.x

## Installation

### 1. Clone the Repository

```bash
git clone https://github.com/UniteOpenSource/microHelium.git
cd microHelium
```

### 2. Install PHP Dependencies

```bash
composer install
```

### 3. Install Node.js Dependencies

```bash
npm install
```

### 4. Environment Configuration

```bash
cp .env.example .env
php artisan key:generate
```

Edit `.env` and configure your database connection:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=microhelium
DB_USERNAME=your_username
DB_PASSWORD=your_password
```

### 5. Run Migrations

```bash
php artisan migrate
```

### 6. Seed Default Data (Optional)

```bash
php artisan db:seed
```

### 7. Build Frontend Assets

```bash
npm run build
```

### 8. Start Development Server

```bash
php artisan serve
```

Visit `http://localhost:8000` in your browser.

## Auto-Judge Setup

The judge runs untrusted code, so its isolation is not optional. Since #49
that isolation is **bubblewrap**, and since #86 a cgroup v2 memory cap.

> **If you followed an older copy of this section, stop.** It told you to
> compile `safeexec` as setuid root and to build a `/bocajail` chroot with
> debootstrap. Neither is used any more: `safeexec` was removed in #96, and
> `AUTOJUDGE_JAIL_PATH` is read by nothing. A setuid-root binary you do not
> need is a liability, so remove it if you created one.

### 1. Install the toolchains and the sandbox

```bash
# Ubuntu/Debian
sudo apt-get install bubblewrap util-linux \
    gcc g++ openjdk-17-jdk python3
```

`bubblewrap` is what confines a submission; `util-linux` provides the
`setpriv` the judge container uses to drop privileges. The language
packages are whichever ones your contest configures.

### 2. Let bubblewrap create user namespaces

On Ubuntu 24.04 and later, unprivileged user namespaces are restricted by
AppArmor and bwrap cannot start:

```bash
sudo sysctl -w kernel.apparmor_restrict_unprivileged_userns=0
```

To check it works at all:

```bash
bwrap --unshare-all --dev /dev \
    --ro-bind-try /usr /usr --ro-bind-try /bin /bin \
    --ro-bind-try /lib /lib --ro-bind-try /lib64 /lib64 \
    /bin/sh -c 'echo sandbox ok'
```

`--ro-bind-try` rather than `--ro-bind` because which of those directories
exist varies by distribution — on some `/bin` is a symlink into `/usr`, on
others `/lib64` is absent. Note that `/` is never bound: binding it
read-only would confine writes but leave every file on the host readable,
`.env` included.

### 3. Configure

```env
AUTOJUDGE_ENABLED=true
AUTOJUDGE_TIME_LIMIT=10
AUTOJUDGE_MEMORY_LIMIT=512
AUTOJUDGE_USE_BWRAP=true
```

`config/autojudge.php` documents the rest, including which host paths the
sandbox mounts read-only (`sandbox_paths` — the application root is never
among them) and the per-language address-space grace table.

### 4. Start one or more workers

```bash
php artisan autojudge:start
```

**More than one is fine, and helps.** Workers claim runs under a row lock,
so two never take the same submission (#126). Measured on a 10-CPU machine,
eight workers judge about 3.6x as fast as one, saturating near the CPU
count — see `docs/specs/126-judging-throughput.md`.

One caveat from that measurement: **on SQLite this does not scale at all.**
SQLite serialises writes and judging writes several times per run, so extra
workers add nothing. Use MySQL if you want the throughput.

### 5. Memory limits (optional, recommended)

A cgroup v2 `memory.max` is the only memory limit that works for every
language — the JVM and .NET reserve gigabytes of address space before
running a line of submitted code, so `ulimit -v` cannot cap them. It needs
a writable cgroup hierarchy, which Docker only gives a `--privileged`
container. `docker-compose.yml` already runs the judge that way.

Without it, judging still works and still produces an MLE verdict, measured
from peak RSS instead — it just bounds nothing, since the verdict arrives
after the memory has been taken.

## Distributed Judging (judgehosts)

When one machine's CPUs are not enough, judging can be spread across
machines — including machines in a partner institution's rack. **Read
step 4 above first**: adding workers on the machine you already have is
cheaper than adding machines, and for most contests it is enough.

A judgehost **pulls**. Nothing ever connects to it, so it needs no inbound
port and no hole in anyone's firewall. It also needs **no database
credentials**: judging a run issues no queries at all, which is what makes
it safe to hand a machine to someone else.

### On the server: issue a credential per machine

```bash
php artisan judgehost:create judge-ufscar-01
```

The token is printed **once** and stored only as a sha256. There is no way
to recover it; a lost token means issuing a new credential and disabling
the old one. Give one credential per machine — the credential is the
identity, and sharing one lets any holder speak for any host.

### On the judge machine: run the agent

Same image as the local judge, plus two settings:

```env
JUDGEHOST_SERVER=https://contest.example.org
JUDGEHOST_TOKEN=<the token printed above>
```

```bash
php artisan judgehost:work
```

The token comes from the environment rather than the command line so it
does not sit in `ps` output for every user on the box.

`--once` judges at most one run and stops, which is the way to test a new
machine without leaving a daemon behind.

### What the agent does, and refuses to do

At boot it registers, which releases any work it was still holding from a
previous life, and reports which languages it can actually run — probed
from the machine, not configured, so a runtime installed or removed is
corrected by restarting the agent.

It then polls for work, backing off when there is none, and for each run
fetches the source, the test data, and any custom compile/run/compare
script the problem defines.

**It gives a run back rather than judging it half-equipped.** If it cannot
fetch a problem's custom checker, or a transfer does not match the digest
the server sent, it returns the run with a reason instead of judging by
rules the problem replaced. That matters more than it sounds: a problem
with a tolerance checker, judged by exact comparison, marks correct
submissions wrong — silently, and in favour of the wrong answer.

A run enough hosts refuse stops being offered to hosts and is logged as an
error for the organisers, rather than circulating the queue forever.

### Use identical machines

ICPC's CCS requirements describe auto-judging machines *identical to the
team machines*, and DOMjudge documents uniform hardware as how a fair,
reproducible setup is obtained. The reason is TLE: the same submission can
pass on a fast judge and time out on a slow one.

microHelium records each host's CPU count and warns when judges diverge,
but it deliberately does **not** compensate — a correction factor measured
once tracks neither cache contention nor thermal throttling, so it would
feel fair while the verdicts went on varying. Use matching hardware.

## Problem Package Format

Problems are uploaded as ZIP files with the following structure:

```
problem.zip/
├── description/
│   ├── problem.info          # basename, fullname, descfile
│   └── problem.pdf           # Problem statement
├── compile/
│   ├── c, cpp, java, py3     # Compilation scripts
├── run/
│   ├── c, cpp, java, py3     # Execution scripts
├── compare/
│   ├── c, cpp, java, py3     # Output comparison scripts
├── input/
│   ├── 1, 2, 3, ...          # Test input files
├── output/
│   ├── 1, 2, 3, ...          # Expected output files
├── limits/
│   └── c, cpp, java, py3     # Time/memory limits per language
└── tests/
    └── validate              # Optional validation scripts
```

### Example problem.info

```
basename=hello
fullname=Hello World Problem
descfile=problem.pdf
```

## API Endpoints

### Authentication

Every route below the Authentication heading is behind `auth:sanctum` and
needs a bearer token. Issue #159: `POST /api/login` and `POST /api/logout`
were listed here for a long time and never existed -- there was no way at
all to obtain a token, because the Sanctum table was never migrated and no
route or command ever called `createToken()`. The routes below are the real
ones.

- `POST /api/tokens` - Exchange credentials for a token (unauthenticated,
  throttled at 5/minute). `login` takes the account's e-mail **or** its
  username.
- `GET /api/tokens` - List your own tokens (never the token itself)
- `DELETE /api/tokens/current` - Revoke the token you are calling with
- `DELETE /api/tokens/{id}` - Revoke one of your own tokens
- `GET /api/user` - Get authenticated user

```bash
TOKEN=$(curl -sS -X POST https://contest.example.org/api/tokens \
  -H 'Accept: application/json' \
  -d login=equipe01 -d password=... -d device_name=notebook-da-equipe \
  | jq -r .token)

curl -sS https://contest.example.org/api/user -H "Authorization: Bearer $TOKEN"
```

The plain text is shown **once** -- only its sha256 is stored, the same
contract as `judgehost:create` above -- and the token expires after
`SANCTUM_TOKEN_MINUTES` (default 30 days). A disabled account gets no token,
and neither does an account whose site pins an address range it is not
calling from (issue #50): otherwise this route would be the way around the
network lock the web login enforces.

### Contests
- `GET /api/contests` - List all contests
- `POST /api/contests` - Create new contest
- `GET /api/contests/{id}` - Get contest details
- `PUT /api/contests/{id}` - Update contest
- `DELETE /api/contests/{id}` - Delete contest

### Problems
- `GET /api/problems` - List problems for current contest
- `POST /api/problems` - Create/upload problem
- `GET /api/problems/{id}` - Get problem details
- `GET /api/problems/{id}/download` - Download problem statement

### Submissions
- `GET /api/runs` - List user submissions
- `POST /api/runs` - Submit solution
- `GET /api/runs/{id}` - Get submission details
- `GET /api/runs/{id}/source` - Download source code

### Scoreboard
- `GET /api/contests/{id}/scoreboard` - Get current scoreboard
- `GET /api/contests/{id}/scoreboard/export` - Export scoreboard data (staff only:
  it reads the standings with the freeze NOT applied)

### Clarifications
- `GET /api/clarifications` - List clarifications
- `POST /api/clarifications` - Submit clarification request
- `PUT /api/clarifications/{id}/answer` - Answer clarification (judges)

## Configuration

### Contest Settings

| Setting | Description | Default |
|---------|-------------|---------|
| `duration` | Contest duration in minutes | 300 |
| `penalty` | Penalty per wrong submission (minutes) | 20 |
| `freeze_time` | Minutes before end to freeze scoreboard | 60 |
| `max_file_size` | Maximum submission file size (KB) | 100 |

### Security Settings

| Setting | Description | Default |
|---------|-------------|---------|
| `ip_restriction` | Enable IP-based login restriction | false |
| `multi_login` | Allow simultaneous logins | false |
| `session_timeout` | Session timeout in minutes | 120 |

## User Roles

| Role | Permissions |
|------|------------|
| **Admin** | Full system access, contest management, user management |
| **Judge** | Evaluate submissions, answer clarifications, view all runs |
| **Staff** | Task management, balloon delivery, printing |
| **Team** | Submit solutions, view scoreboard, ask clarifications |
| **Score** | View-only scoreboard access |

## Tech Stack

### Backend
- **Framework**: Laravel 13
- **Language**: PHP 8.3+
- **Database**: MySQL/PostgreSQL/SQLite
- **Queue**: Laravel Horizon / Redis
- **Authentication**: Laravel Sanctum

### Frontend
- **Framework**: Vue.js 3.5
- **Build Tool**: Vite 6
- **CSS Framework**: Tailwind CSS 4 + Bootstrap 5
- **Charts**: Chart.js 4
- **Icons**: Font Awesome 6

### DevOps
- **Container**: Docker / Laravel Sail
- **Testing**: PHPUnit 12, Pest
- **Code Style**: Laravel Pint
- **CI/CD**: GitHub Actions

## Deployment & Rollback

Production images are built and tagged with the git sha that produced them, so rolling back is re-pointing a tag and restarting -- no rebuild, no migration replay. (See issue #54.)

### Deploying

```bash
make deploy
```

This builds `microhelium-app`/`microhelium-judge` images tagged with the current git sha, promotes that sha to the `:prod` tag the containers actually run, restarts the stack, runs pending migrations, and then runs `make smoke` as a gate -- if the smoke checks fail, the deploy is reported as failed (the new containers are left running for inspection; roll back explicitly if needed).

### Rolling back

List what's available to roll back to, then re-point `:prod` at a previous build (no rebuild):

```bash
docker images microhelium-app   # find a previous <tag>
make rollback PREV=<tag>
```

`make rollback` re-tags `microhelium-app:<tag>` and `microhelium-judge:<tag>` as `:prod`, restarts the stack with `--no-build`, and runs the smoke gate again to confirm the rollback is healthy.

**This does not revert database migrations.** If the deploy being rolled back ran a schema-breaking migration (a dropped/renamed column the old code still reads), re-pointing the image alone won't restore compatibility -- run that migration's `down()` manually first.

### Smoke checks

```bash
make smoke
```

Runs two layers of checks against the live stack: the PHPUnit `Smoke` suite (`tests/Smoke/RouteHealthSmokeTest.php`) inside the running `app` container (safe against production data -- it uses `phpunit.xml`'s in-memory sqlite), and plain `curl` checks against `/up`, `/api/health`, and `/login` through the real webserver/nginx path. `make deploy` and `make rollback` both run this automatically as a gate; run it manually any time to verify the currently-running stack is healthy.

## Contributing

1. Fork the repository
2. Create your feature branch (`git checkout -b feature/amazing-feature`)
3. Commit your changes (`git commit -m 'Add some amazing feature'`)
4. Push to the branch (`git push origin feature/amazing-feature`)
5. Open a Pull Request

## Security

If you discover a security vulnerability, please send an email to security@example.com. All security vulnerabilities will be promptly addressed.

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

## Acknowledgments

- **BOCA Online Contest Administrator** - Original inspiration for contest management features
- **Laravel Framework** - The PHP framework for web artisans
- **Vue.js** - The progressive JavaScript framework
- **ACM-ICPC** - International Collegiate Programming Contest standards

## Support

- **Documentation**: [Wiki](https://github.com/UniteOpenSource/microHelium/wiki)
- **Issues**: [GitHub Issues](https://github.com/UniteOpenSource/microHelium/issues)
- **Discussions**: [GitHub Discussions](https://github.com/UniteOpenSource/microHelium/discussions)

---

Made with love for the competitive programming community.
