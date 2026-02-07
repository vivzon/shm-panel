# Contributing to SHM Panel

Thank you for your interest in contributing to SHM Panel! We welcome contributions from everyone.

## Getting Started

1.  **Fork the Repository**: Click the "Fork" button on the top right of the repository page.
2.  **Clone Your Fork**:
    ```bash
    git clone https://github.com/yourusername/shm-panel.git
    cd shm-panel
    ```
3.  **Create a New Branch**:
    ```bash
    git checkout -b feature/your-feature-name
    ```

## Development Workflow

1.  **Understand the Codebase**: Review `ARCHITECTURE.md` to understand how the frontend (PHP) and backend (Bash) interact.
2.  **Make Changes**: Implement your feature or fix.
3.  **Test Locally**:
    -   Since SHM Panel interacts closely with the OS (users, nginx, etc.), it is highly recommended to test on a **fresh Virtual Machine** (Ubuntu 20.04+ or Debian 11+).
    -   **Do not test on a production server.**

## Pull Request Guidelines

1.  **Descriptive Title**: Use a clear and descriptive title for your Pull Request (PR).
2.  **Detailed Description**: Explain what changes you made and why.
3.  **One Feature per PR**: Keep your PRs focused on a single feature or fix.
4.  **Code Style**:
    -   **PHP**: Follow PSR-12 coding standards.
    -   **Bash**: Use descriptive variable names and comments.

## Reporting Issues

If you find a bug or have a feature request, please open an issue on GitHub. Include:
-   **Description**: What is the issue?
-   **Steps to Reproduce**: How can we trigger the bug?
-   **Environment**: OS version, PHP version, etc.
-   **Logs**: Relevant logs from `/var/log/shm-manage.log` or Nginx error logs.

## Security Vulnerabilities

If you discover a security vulnerability, please do **NOT** open a public issue. Email us directly at `security@yourdomain.com` (replace with actual security contact if available).

---

Thank you for helping make SHM Panel better!
