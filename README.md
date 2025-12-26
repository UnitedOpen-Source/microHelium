# MicroHelium

A comprehensive **Hackathon and Programming Contest Management Platform** built with Laravel 12, inspired by the BOCA Online Contest Administrator. MicroHelium provides a modern, feature-rich environment for organizing competitive programming events, hackathons, and CTF competitions.

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

- **PHP**: >= 8.2
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

For automatic code evaluation, you need to set up the auto-judge system:

### 1. Install Required Compilers

```bash
# Ubuntu/Debian
sudo apt-get install gcc g++ openjdk-17-jdk python3 gpc

# Fedora/RHEL
sudo dnf install gcc gcc-c++ java-17-openjdk python3
```

### 2. Create Sandbox Environment (Recommended)

```bash
# Create jail directory
sudo mkdir -p /bocajail
sudo debootstrap --arch=amd64 jammy /bocajail http://archive.ubuntu.com/ubuntu
```

### 3. Compile Safe Execution Tool

```bash
cd tools
gcc -O2 -o safeexec safeexec.c
sudo chown root:root safeexec
sudo chmod 4555 safeexec
```

### 4. Configure Auto-Judge

Edit your `.env` file:

```env
AUTOJUDGE_ENABLED=true
AUTOJUDGE_JAIL_PATH=/bocajail
AUTOJUDGE_TIME_LIMIT=10
AUTOJUDGE_MEMORY_LIMIT=512
```

### 5. Start Auto-Judge Daemon

```bash
php artisan autojudge:start
```

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
- `POST /api/login` - User authentication
- `POST /api/logout` - User logout
- `GET /api/user` - Get authenticated user

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
- `GET /api/scoreboard` - Get current scoreboard
- `GET /api/scoreboard/export` - Export scoreboard data

### Clarifications
- `GET /api/clarifications` - List clarifications
- `POST /api/clarifications` - Submit clarification request
- `PUT /api/clarifications/{id}` - Answer clarification (judges)

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
- **Framework**: Laravel 12
- **Language**: PHP 8.2+
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
- **Testing**: PHPUnit 11, Pest
- **Code Style**: Laravel Pint
- **CI/CD**: GitHub Actions

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
