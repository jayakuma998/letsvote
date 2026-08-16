# LetsVote

A small PHP voting application, written to be deployed **by hand** on AWS so a
class can walk through every service in the architecture diagram and see what
each one actually does.

People sign up, register as voters, cast one secret ballot, and an
administrator opens/closes voting and publishes the results.

> This is a teaching project. It is **not** an electoral system, it is not
> affiliated with any electoral authority, and the sample candidates are
> fictional. Real elections need auditability, independent observation and a
> legal framework that no classroom app can provide.

---

## What runs where

| Diagram box | What the code does with it |
|---|---|
| **Amazon Cognito** | Owns all credentials. `login.php` redirects to the Hosted UI; `callback.php` verifies the returned ID token. The app never sees a password. |
| **ALB + Auto Scaling** | Two instances, one per AZ. Sessions live in MySQL (`DbSessionHandler`) so either instance can serve any request. `/health.php` is the target-group health check. |
| **RDS primary** | Every write: registrations, ballots, sessions (`Db::write()`). |
| **RDS read replica** | Every result and tally (`Db::read()`), so results traffic never touches the box still accepting votes. |
| **Secrets Manager** | Database password and Cognito client secret. `deploy/userdata.sh` reads the secret at boot and writes `/etc/letsvote/config.ini`. |
| **NAT gateway** | How the private instances reach Cognito's token endpoint and Secrets Manager. |
| **CloudFront + ACM + Route 53** | TLS and the public name. `Http::isHttps()` reads `X-Forwarded-Proto` because the instances only ever see plain HTTP. |
| **WAF + Shield** | Nothing in the code; configured in the console. |

## Requirements

- PHP **8.1 or newer** (Amazon Linux 2023 ships 8.2) with `pdo_mysql`, `mbstring`, `openssl`, `curl`, `json`
- MySQL 8.0 (Amazon RDS)
- **No Composer and no third-party libraries.** JWT verification is ~150 lines
  of `src/Jwt.php` so students can read exactly how a signature check works.

## Layout

```
public/       the only directory the web server exposes (DocumentRoot)
  index.php     landing page + candidate list
  login.php     redirect to the Cognito Hosted UI
  callback.php  code -> tokens -> verified claims -> local session
  logout.php    kill our session, then Cognito's
  register.php  voter registration (ID, date of birth, region)
  vote.php      the ballot; one vote per account
  results.php   tally, served entirely from the read replica
  admin.php     open/close voting, publish results
  health.php    ALB health check (shallow on purpose — see the file)

src/          classes, loaded by plain require in bootstrap.php
templates/    HTML, kept out of the DocumentRoot
sql/          schema.sql and seed_candidates.sql
deploy/       Apache vhost + EC2 user-data script
config/       config.example.ini (the real file lives in /etc/letsvote)
```

## Security decisions worth discussing in class

- **Ballot secrecy.** A vote writes two unlinked rows in one transaction:
  `voter_receipts` records *who voted*, `ballots` records *what was voted for*.
  Neither table can be joined to the other. The ballot timestamp is rounded to
  the hour so the two cannot be matched up by time either.
- **One vote per person** is enforced by the `PRIMARY KEY` on
  `voter_receipts.user_id`, not by an `if` statement. Two rapid clicks in two
  tabs cannot both succeed.
- **CSRF tokens** on every state-changing POST (`Csrf::verify()`).
- **Config outside the web root**, mode `0640`, owned by `root:apache`.
- **The app connects as `letsvote_app`**, not the RDS master user, and that
  account has no DDL rights.
- **`set +x` around the secret** in `userdata.sh` — otherwise the database
  password lands in `/var/log/cloud-init-output.log`.
- **The health check is shallow.** If it tested the database, one RDS blip
  would fail both instances and the Auto Scaling group would delete the fleet.

## Running it locally

You need PHP 8.1+ and a MySQL you can reach.

```bash
mysql -u root -p < sql/schema.sql
mysql -u root -p letsvote < sql/seed_candidates.sql

cp config/config.example.ini config/config.local.ini
# edit db.* and cognito.*, and set base_url = "http://localhost:8000"

LETSVOTE_CONFIG=config/config.local.ini php -S localhost:8000 -t public
```

Cognito needs `http://localhost:8000/callback.php` added to the app client's
allowed callback URLs (Cognito permits `http` for `localhost` only).

Make yourself an admin after your first sign-in:

```sql
UPDATE users SET is_admin = 1 WHERE email = 'you@example.com';
```

## Deploying to AWS

Follow [`DEPLOYMENT.md`](DEPLOYMENT.md) — a console-by-console runbook that
builds the diagram in the order the dependencies require.
