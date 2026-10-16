# Security

heLMSman stores student work and grades, so security reports are welcome and taken seriously.

## Reporting a problem

Please **do not open a public issue** for a security problem. Use GitHub's
"Report a vulnerability" button on this repository (Security tab → Advisories),
or email the maintainer at drkoepsell@gmail.com. Include the steps to reproduce
the problem and the version you tested.

You should get a reply within a week. Fixes are released as a new version, and the
CHANGELOG credits the reporter unless they ask not to be named.

## How heLMSman protects data

- Data files live in `data/`. They are blocked by `.htaccess`, and each one also
  starts with a PHP exit line, so a host that ignores `.htaccess` still won't display it.
- Student uploads are saved under random names with a `.bin` extension, and course
  files are kept in a randomly named folder. Every file is sent through a
  permission check. A student can open a course file only if something they can
  already see links to it. Executable file types (`.php`, `.phtml`, `.phar` and
  similar, including double extensions and trailing dots or spaces) are refused.
- **Site → Server check** tests whether the data folder can be downloaded directly
  (for example on nginx, which ignores `.htaccess`). If it can, the dashboard
  shows a warning explaining how to move the data folder out of the web folder.
- Passwords are hashed with PHP's `password_hash`. Failed logins are rate-limited.
  Resetting a password or disabling an account signs that person out everywhere.
- Every form that changes data carries a CSRF token.
- Text that students write is escaped before it is shown. Only instructors can
  write HTML, which is rendered as-is by design, so give instructor accounts
  only to people you trust.

## What you are responsible for

- Serve the site over **HTTPS**.
- Keep PHP up to date on your host.
- Back up the `data/` folder.
- Follow your institution's rules for student records (in the US, FERPA). Many
  institutions require official grades to be kept in their own LMS. The
  gradebook's CSV export is there to help you move grades into it.
