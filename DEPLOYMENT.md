# Deploying LetsVote on AWS — manual console runbook

Every step is done **by hand in the AWS console**, in this order. The order
matters: each step produces an ID the next step needs.

Region for the whole build: **us-east-2** (Ohio).

> **Two things stay in us-east-1 no matter which Region you build in**, because
> AWS pins them there:
> - the **CloudFront viewer certificate** in ACM ([step 8](#8-acm-certificate)) —
>   CloudFront accepts custom certificates only from us-east-1
> - the **WAF web ACL** scoped to CloudFront ([step 17](#17-waf))
>
> This is why [step 8](#8-acm-certificate) asks for **two** certificates. In a
> single-Region us-east-1 build one certificate would cover both.

> **This copy is filled in for a specific build:** domain `letsvotes.com`,
> AWS account `860977520909`, application repository
> `https://github.com/jayakuma998/letsvote.git`. If you are building your own,
> substitute your own registered domain, account ID and repository everywhere
> they appear below.

> **Console steps verified against AWS documentation on 11 August 2026.**
> Console wording drifts. Where AWS has recently renamed things, both the old
> and new labels are given. Source links are at the bottom of each section.

---

## Contents

| # | Step | Depends on |
|---|---|---|
| 0 | [Before you start](#0-before-you-start) | — |
| 1 | [VPC and subnets](#1-vpc-and-subnets) | — |
| 2 | [Internet gateway, NAT, route tables](#2-internet-gateway-nat-gateway-route-tables) | 1 |
| 3 | [Security groups](#3-security-groups) | 1 |
| 4 | [Secrets Manager](#4-secrets-manager) | — |
| 5 | [RDS primary + read replica](#5-rds-primary--read-replica) | 1, 3, 4 |
| 6 | [Cognito user pool](#6-cognito-user-pool) | — |
| 7 | [IAM role for the instances](#7-iam-role-for-the-instances) | 4 |
| 8 | [ACM certificate](#8-acm-certificate) | domain |
| 9 | [Launch template](#9-launch-template) | 3, 7 |
| 10 | [Target group](#10-target-group) | 1 |
| 11 | [Application Load Balancer](#11-application-load-balancer) | 1, 3, 8, 10 |
| 12 | [Auto Scaling group](#12-auto-scaling-group) | 9, 10 |
| 13 | [Load the database schema](#13-load-the-database-schema) | 5, 12 |
| 14 | [CloudFront](#14-cloudfront) | 8, 11 |
| 15 | [Route 53](#15-route-53) | 11, 14 |
| 16 | [Lock the ALB to CloudFront](#16-lock-the-alb-to-cloudfront) | 11, 14 |
| 17 | [WAF](#17-waf) | 14 |
| 18 | [Teardown](#18-teardown-in-this-order) | — |
| 19 | [Troubleshooting](#19-when-it-does-not-work) | — |

---

## 0. Before you start

**The domain.** Already done for this build: **`letsvotes.com`** is registered
in Route 53 (hosted zone `Z06464701RGI48KVNHF25`) with auto-renew on. Note the
plural — `letsvote.com` without the `s` has been registered by someone else for
years, which is why this build uses `letsvotes.com`. That single character has
to be right in the ACM certificate, the Cognito callback URL and the CloudFront
CNAMEs, or you get the `redirect_mismatch` and 502 failures in
[troubleshooting](#19-when-it-does-not-work).

If you are building your own copy, register a domain **first** — ACM validation
in step 8 depends on it — and confirm the ICANN verification email, or the
registrar suspends the domain 15 days later. `.click` / `.link` / `.info` cost
a few dollars a year.

**Cost.** This is not free tier. Rough us-east-2 monthly estimate:

| Item | ~USD / month |
|---|---|
| NAT gateway (hourly + data) | 33+ |
| Application Load Balancer | 17+ |
| 2 × t3.micro EC2 | 15 |
| RDS db.t4g.micro primary + replica | 25 |
| WAF web ACL + managed rules | 7+ |
| Route 53 hosted zone | 0.50 |
| CloudFront (light traffic) | ~1 |
| **Cognito** | **$0** — see below |
| **Total** | **~100** |

**Cognito is free for a class.** Both the Lite and Essentials feature plans
include **10,000 monthly active users free per account**, so a classroom costs
nothing. Above that, Essentials is $0.015/MAU and Lite $0.0055/MAU.

> ### Do not enable Shield Advanced
> Shield **Standard** is automatic, free, and is what the diagram means. Shield
> **Advanced** is **$3,000/month on a one-year commitment** and cannot be
> cancelled. If you read one warning in this document, read this one.

Set an AWS Budgets alert for $20 before building anything, and read
[teardown](#18-teardown-in-this-order) — leaving this running after class is
what produces surprise bills.

---

## 1. VPC and subnets

VPC → *Create VPC* → **VPC only** (create each piece by hand so the class sees
all of them).

- Name `letsvote-vpc`, IPv4 CIDR `172.16.0.0/16`
- Then *Actions* → *Edit VPC settings* → enable **DNS hostnames** and
  **DNS resolution**. RDS endpoints do not resolve without these.

Create six subnets (VPC → Subnets → *Create subnet*):

| Name | AZ | CIDR | Purpose |
|---|---|---|---|
| `public-subnet-01` | us-east-2a | `172.16.0.0/24` | ALB |
| `public-subnet-02` | us-east-2b | `172.16.1.0/24` | ALB + NAT gateway |
| `webapp-subnet-01` | us-east-2a | `172.16.2.0/24` | EC2 |
| `webapp-subnet-02` | us-east-2b | `172.16.3.0/24` | EC2 |
| `database-subnet-01` | us-east-2a | `172.16.4.0/24` | RDS primary |
| `database-subnet-02` | us-east-2b | `172.16.5.0/24` | RDS read replica |

For both **public** subnets only: *Actions* → *Edit subnet settings* → tick
**Enable auto-assign public IPv4 address**. Leave the other four off.

## 2. Internet gateway, NAT gateway, route tables

1. VPC → *Internet gateways* → create `letsvote-igw` → **Attach to VPC**.
2. VPC → *Elastic IPs* → *Allocate*, name it `letsvote-nat-eip`.
3. VPC → *NAT gateways* → create `letsvote-nat` in **`public-subnet-02`**,
   connectivity **Public**, allocate the Elastic IP. Takes a few minutes.
4. VPC → *Route tables* → create three:

| Route table | Routes | Associated subnets |
|---|---|---|
| `public-rt` | `0.0.0.0/0` → `letsvote-igw` | public-subnet-01, public-subnet-02 |
| `webapp-rt` | `0.0.0.0/0` → `letsvote-nat` | webapp-subnet-01, webapp-subnet-02 |
| `db-rt` | *(local only — no internet route)* | database-subnet-01, database-subnet-02 |

The `local` route for `172.16.0.0/16` is automatic; don't delete it.

> ### Verify `webapp-rt` before moving on
> Creating a route table and *adding a route to it* are two separate actions in
> the console, and it is easy to do the first and skip the second. Open
> `webapp-rt` → **Routes** and confirm you see **two** entries:
>
> | Destination | Target |
> |---|---|
> | `172.16.0.0/16` | `local` |
> | `0.0.0.0/0` | `nat-…` |
>
> If only the `local` route is there, the instances have no path off the VPC.
> `deploy/userdata.sh` then fails at `dnf -y update`, never installs PHP, never
> reads Secrets Manager, and never answers `/health.php` — which you will not
> discover until the target group sits **unhealthy** at
> [step 12](#12-auto-scaling-group), ten steps later. Also confirm both
> `webapp-subnet-01` and `webapp-subnet-02` appear under **Subnet
> associations**.

**Checkpoint.** The database subnets now have no path to or from the internet.
That is the point of a separate data tier.

## 3. Security groups

VPC → *Security groups*. Create all three empty first, then add rules — they
reference each other.

**`letsvote-lb-sg`** (on the ALB)

| Direction | Type | Port | Source/Destination |
|---|---|---|---|
| Inbound | HTTPS | 443 | `0.0.0.0/0` (tightened in [step 16](#16-lock-the-alb-to-cloudfront)) |
| Inbound | HTTP | 80 | `0.0.0.0/0` (only to redirect to 443) |
| Outbound | HTTP | 80 | `letsvote-webapp-sg` |

**`letsvote-webapp-sg`** (on the EC2 instances)

| Direction | Type | Port | Source/Destination |
|---|---|---|---|
| Inbound | HTTP | 80 | **`letsvote-lb-sg`** — a security group, not a CIDR |
| Outbound | HTTPS | 443 | `0.0.0.0/0` (Cognito, Secrets Manager, dnf) |
| Outbound | MySQL | 3306 | `letsvote-db-sg` |

**`letsvote-db-sg`** (on RDS)

| Direction | Type | Port | Source/Destination |
|---|---|---|---|
| Inbound | MySQL | 3306 | **`letsvote-webapp-sg`** |

No SSH rule anywhere. You reach instances with **SSM Session Manager** — no
open port, no key pair, no bastion. This is also what makes the
`X-Forwarded-For` header trustworthy: nothing on the internet can reach an
instance directly to forge it.

## 4. Secrets Manager

Secrets Manager, **in us-east-2** — a secret is a regional resource, and
`deploy/userdata.sh` reads it with `--region us-east-2`. One created in another
Region is invisible to the instances and the boot fails with
`Missing required config value 'db.host'`.

*Store a new secret* → **Other type of secret** → *Plaintext*. Paste this and
fill in the `TBD`s in steps 5 and 6.

```json
{
  "db_host": "TBD",
  "db_host_read": "TBD",
  "db_name": "letsvote",
  "db_user": "letsvote_app",
  "db_pass": "GENERATE-A-LONG-RANDOM-PASSWORD",
  "cognito_user_pool_id": "TBD",
  "cognito_client_id": "TBD",
  "cognito_client_secret": "TBD",
  "cognito_domain": "TBD"
}
```

Secret name **`letsvote/app-config`**. Disable rotation. Copy the secret
**ARN** — step 7 needs it.

## 5. RDS primary + read replica

1. RDS → *Subnet groups* → *Create*: `letsvote-subnet-group`, VPC
   `letsvote-vpc`, AZs `us-east-2a` + `us-east-2b`, subnets
   `database-subnet-01` and `database-subnet-02`.

2. RDS → *Create database* → **Standard create** → **MySQL 8.0**
   - Template **Dev/Test** (Free tier will not let you add a read replica)
   - DB instance identifier `database-1`
   - Master username `admin`; save this password in your own notes — it is
     **not** the application password
   - `db.t4g.micro`, 20 GiB gp3
   - Availability: Single-AZ is fine for class; Multi-AZ doubles the cost
   - Connectivity: VPC `letsvote-vpc`, the subnet group above,
     **Public access = No**, security group **`letsvote-db-sg`**
   - Additional configuration → Initial database name: **leave blank**
     (`sql/schema.sql` creates it)
   - **Backup → keep automated backups ON with retention ≥ 1 day.**

   > **This one is a hard requirement, not advice.** AWS: *"you must enable
   > automatic backups on the source DB instance by setting the backup
   > retention period to a value other than 0."* With retention at 0 the
   > **Create read replica** action is unavailable and step 3 below fails.

3. Wait for **Available** → select `database-1` → *Actions* → **Create read
   replica**
   - DB instance identifier `database-1-replica`
   - **Availability Zone `us-east-2b`**, same instance class,
     **Public access = No**, security group `letsvote-db-sg`
   - Keep it in the **same VPC** as the source — AWS warns that a replica in a
     different VPC can hit CIDR overlap and become unstable

4. Copy both endpoints into the Secrets Manager secret (`db_host`,
   `db_host_read`).

A read replica is **read-only** — MySQL rejects writes against it. That is
exactly why `Db::read()` is used only for tallies.

> ### What is actually built right now
> `database-1` exists and is **Available**, but it does not match the spec
> above: **db.m7g.large, Multi-AZ, 200 GiB, MySQL 8.4.9**, and it has **no read
> replica yet**. That is roughly the RDS console's default selection rather
> than a deliberate choice, and it costs many times the `$25/month` in the
> [cost table](#0-before-you-start) — the single largest line in this build.
> Decide whether to keep it or rebuild it small before going further.
>
> **MySQL 8.4 vs 8.0.** The application works on either; nothing in
> `sql/schema.sql` is version-specific. The one thing to know is that 8.4
> creates users with `caching_sha2_password`, which PHP's `mysqlnd` driver
> handles — so the `CREATE USER` in [step 13](#13-load-the-database-schema)
> needs no `WITH mysql_native_password` clause. If you rebuild the instance,
> either version is fine.

*Docs: [Creating a read replica](https://docs.aws.amazon.com/AmazonRDS/latest/UserGuide/USER_ReadRepl.Create.html)*

## 6. Cognito user pool

Console (note the Region in the URL):
<https://us-east-2.console.aws.amazon.com/cognito/v2/idp/user-pools?region=us-east-2>

> ### The console changed — this step was rewritten
> AWS replaced the long multi-page "Create user pool" wizard with a single
> **Define your application** screen. The practical differences:
>
> - **The pool and its first app client are now created together.** There is no
>   longer a separate "create an app client" step afterwards. The old 6a/6c
>   split in this runbook no longer matches anything on screen.
> - **Most of the old wizard is gone from creation.** Password policy, MFA,
>   email provider, self-service sign-up and attribute verification are no
>   longer asked for up front — they are edited *after* the pool exists.
> - **The choice that used to be "Confidential client"** is now the
>   **Application type** at the very top of the creation screen, and it decides
>   whether you get a client secret. See the warning in 6a.
>
> If your screen still shows the older long wizard, AWS has not rolled the new
> console out to this Region yet. Everything below still applies; the settings
> are just spread across more pages.

> ### Create this pool in us-east-2
> **A user pool cannot be moved between Regions**, and the Region is baked into
> both the pool ID (`us-east-2_XXXXXXXXX`) and the token issuer URL
> (`https://cognito-idp.us-east-2.amazonaws.com/...`) that `src/Jwt.php`
> validates against. Build it in the wrong Region and the fix is to delete it
> and start step 6 again.
>
> Confirm the Region selector in the console top-right reads **Ohio
> (us-east-2)** before you click *Create user pool*.

### 6a. Create the pool and its app client

Cognito → **User pools** → *Create user pool*. (The console may also offer
*Get started for free in less than five minutes* — same flow.)

**Under "Define your application":**

| Field | Choose |
|---|---|
| **Application type** | **`Traditional web application`** |
| **Name your application** | `letsvote-web` |

> ### Application type is irreversible, and it is the one that matters
> **`Traditional web application` is the only choice that generates a client
> secret**, and `src/Cognito.php` authenticates to the token endpoint with
> `client_secret_basic` — it cannot work without one.
>
> AWS documents the client secret as unchangeable after creation: to get one
> later you must **create a whole new app client**. Pick
> *Single-page application* by mistake and sign-in fails at the token exchange
> with an error that does not mention the client secret.

**Under "Configure options":**

| Field | Choose |
|---|---|
| **Options for sign-in identifiers** | **Email** |
| **Required attributes for sign-up** | **`email`** and **`name`** |

> ### Both of these are also irreversible
> AWS lists **sign-in options** and **required attributes** among the settings
> you cannot change without creating a new user pool. `name` is easy to miss
> here and the app reads it, so add it now rather than rebuilding later.

**Under "Add a return URL":**

```
https://letsvotes.com/callback.php
```

This is the same value the old console called the *callback URL*. It must match
what `src/Cognito.php` sends, character for character — scheme, host, path, and
no trailing slash.

Choose **Create your application**.

On the **Set up your application** page that follows, AWS offers framework code
examples. Ignore them — this app already implements the flow. Scroll down and
choose **Go to overview**.

**Check before moving on.** The **User pool ID** on the overview page must start
with `us-east-2_`. If it starts with `us-east-1_`, the pool is in the wrong
Region — delete it and redo 6a.

### 6b. Trim the authentication flows

Pool → **App clients** → `letsvote-web` → **Edit app client information**.

The console pre-populates **Authentication flows** based on the application
type. Those flows are for *API-based* sign-in, where your server or SDK handles
credentials directly (`InitiateAuth` / `AdminInitiateAuth`). **This app uses
none of them.** It redirects the browser to managed login and exchanges an
authorization code at the token endpoint, so the user's password never touches
the application.

Untick all of these:

| Flow | Why not |
|---|---|
| `ALLOW_USER_AUTH` (choice-based) | managed login handles the choice |
| `ALLOW_USER_PASSWORD_AUTH` | sends passwords through the app — the thing we are avoiding |
| `ALLOW_USER_SRP_AUTH` | needs client-side SRP libraries this app doesn't use |
| `ALLOW_ADMIN_USER_PASSWORD_AUTH` | server-side password auth; not supported in hosted UI anyway |
| `ALLOW_CUSTOM_AUTH` | needs Lambda triggers we don't have |

Leave **`ALLOW_REFRESH_TOKEN_AUTH`** alone — the console notes refresh token
authentication is always enabled. The app doesn't use refresh tokens either
(`callback.php` verifies the ID token and immediately creates its own
database-backed session), but there is nothing to gain by fighting it.

Every flow you leave ticked is an additional way to authenticate against your
user pool that the application itself never uses. This is a good five-minute
discussion with the class about attack surface.

**The other settings on this page are fine as they come**, with one exception:

- **Prevent user existence errors**: **enable it.** Cognito then returns the
  same generic failure whether or not the account exists, instead of confirming
  which email addresses are registered. On a voting app, a list of registered
  voters is exactly what you don't want to leak.
- **Token expiration** — ID and access tokens at 60 minutes, refresh at 5 days,
  are fine. The app stops caring the moment it mints its own session.
- **Enable token revocation** / **refresh token rotation** — no effect here,
  since the app holds no refresh token.

### 6c. Set the callback and sign-out URLs

**These are not on the "App client information" page.** In the same app client,
open the **Login pages** tab (called **Hosted UI** in some console versions) and
choose **Edit** on the managed login pages configuration:

| Setting | Required value |
|---|---|
| **Allowed callback URLs** | `https://letsvotes.com/callback.php` |
| **Allowed sign-out URLs** | `https://letsvotes.com/` — **note the trailing slash**, unlike the callback URL |
| **OAuth 2.0 grant types** | **Authorization code grant** only |
| **OpenID Connect scopes** | `openid`, `email`, `profile` |

The sign-out URL is not requested anywhere during creation, so it is empty until
you set it here. Without it, `logout.php` sends users to Cognito and Cognito
refuses to redirect them back.

Never enable **Implicit grant** — it returns tokens in the browser URL bar.

For local development you may also add `http://localhost:8000/callback.php`;
Cognito permits plain `http` only for `localhost`.

Then copy the **Client ID** and **Client secret** (revealed with *Show client
secret*) into the Secrets Manager secret.

### 6d. Create the managed login domain

The **Domain** page offers two kinds, and this runbook uses the first:

| | What it is | Needed here? |
|---|---|---|
| **Cognito domain** | a service-owned prefix on `amazoncognito.com` | **yes** — this is the one |
| **Custom domain** | your own DNS name (e.g. `auth.letsvotes.com`) plus its own ACM certificate | no — leave it blank |

**The creation flow already made the Cognito domain for you.** It has a
machine-generated prefix derived from the pool ID, for example:

```
https://us-east-21lectpe5u.auth.us-east-2.amazoncognito.com
```

That is a complete, working managed login domain — the application does not
care what the prefix says. The only drawback is that it is the URL your
students stare at on the sign-in page, and it is neither memorable nor
typeable.

> ### Strip the `https://` before putting this in the secret
> The console shows the domain **with** a scheme. `cognito_domain` must be the
> **hostname only**, because `Cognito::hostedUiBase()` adds the scheme itself:
>
> ```php
> return 'https://' . rtrim(Config::mustGet('cognito.domain'), '/');
> ```
>
> Paste the console value verbatim and every Cognito URL becomes
> `https://https://…`, so sign-in fails before the user ever sees a login page.
>
> | | |
> |---|---|
> | ✅ correct | `us-east-21lectpe5u.auth.us-east-2.amazoncognito.com` |
> | ❌ wrong | `https://us-east-21lectpe5u.auth.us-east-2.amazoncognito.com` |
> | ❌ wrong | `us-east-21lectpe5u.auth.us-east-2.amazoncognito.com/` |

**If you want `letsvote-auth` instead, change it now**, before anything depends
on it: *Delete* the generated domain, then *Create Cognito domain* with the
prefix below. Once the value is in Secrets Manager and instances are running,
changing it means editing the secret and rebooting every instance. Deleting a
domain takes the sign-in pages offline for a minute, which is free right now
and disruptive later.

Also note the pool itself is named something like **`User pool - 4vkocv`** —
the creation flow named your *application* `letsvote-web` but auto-named the
*pool*. Rename it to `letsvote-users` under **Settings** if you want the console
to be readable in class. Unlike most Cognito settings, the pool name **can** now
be changed after creation.

In the pool → **Domain** (or *App integration* → *Domain*) → *Create Cognito
domain* → prefix `letsvote-auth`. Result:
`letsvote-auth.auth.us-east-2.amazoncognito.com`.

- **Branding version**: **new user pools default to Managed login**, and that
  is the right choice — keep it. **Hosted UI (classic)** also works: AWS
  documents that all endpoint paths except `/passkeys/add` are shared between
  the two branding versions, and this app uses none of the differences. Classic
  is the *only* option on the **Lite** feature plan.
- The branding version is a property of **the domain, not the app client**, and
  applies to every app client using that domain.
- Switching branding version later takes up to four minutes, and **Amazon
  Cognito does not preserve user sessions across the switch** — everyone signed
  in has to sign in again. Harmless in class, worth knowing before you flip it
  during a demo.

> ### Leave "Issuer type" set to Original
> Further down the same **Domain** page is an **Issuer** setting with two
> values, and this one will break the application if you change it:
>
> | Issuer type | `iss` claim in tokens |
> |---|---|
> | **Original** ← keep this | `https://cognito-idp.us-east-2.amazonaws.com/us-east-2_XXXXXXXXX` |
> | Updated | `https://issuer-cognito-idp.us-east-2.amazonaws.com/us-east-2_XXXXXXXXX` |
>
> `src/Cognito.php` builds the **Original** form in `issuer()` and compares it
> to the token's `iss` claim with a strict `!==`. Switch to *Updated* and every
> sign-in dies at `ID token issuer mismatch` — after a successful Cognito
> login, which makes it look like the app is at fault rather than a setting
> nobody touched on purpose.
>
> **AWS recommends *Updated* for all user pools**, so this is a plausible thing
> for a helpful student to change. It also isn't compatible with ALB
> authentication or API Gateway Cognito authorizers. If you ever do want it,
> change `issuer()` in `src/Cognito.php` to match — don't change one without
> the other.
- You can't use `aws`, `amazon`, or `cognito` in the prefix.
- A prefix domain takes up to 60 seconds to come up.
- The `us-east-2` in the resulting hostname is not something you choose — it
  comes from the pool's Region. Seeing `us-east-1` there means the pool itself
  is in the wrong Region.
- The prefix only has to be unique **within the Region**, so `letsvote-auth`
  being taken in us-east-1 does not stop you using it in us-east-2. If the
  console rejects it as unavailable, add a suffix (`letsvote-auth-2`) and use
  the result consistently everywhere below.

### 6e. Sign-up and verification settings

These are no longer part of creation. Confirm them in the pool's settings tabs
(**Sign-up**, **Sign-in**, **Messaging**):

- **Self-service sign-up**: **enabled** — otherwise nobody can register
- **Attribute verification**: **Send email message, verify email address**
- **Email provider**: **Send email with Cognito** — capped at **50 emails/day**.
  Fine for a class, nothing more. Real traffic needs SES.
- **MFA**: **No MFA** for class (or optional TOTP to demo it)
- **Password policy**: Cognito defaults are fine

> Keep **"Allow Cognito to automatically send messages to verify and confirm"**
> on. AWS documents that if you instead confirm users as an administrator, the
> login pages show an error after sign-up even though the user was created.

**Feature plan.** Pools still have **Lite / Essentials / Plus**, but it is no
longer a creation-wizard question — new pools default to **Essentials** and you
change it in the pool's settings. Keep Essentials: 10,000 MAU are free, and
*managed login* requires Essentials or Plus. **Lite** is also fine and slightly
cheaper past the free tier, but gives you the classic hosted UI only.

> ### Two managed-login behaviours that look like bugs
> Both are worth mentioning before the class hits them and assumes the app is
> broken:
>
> - **Sign-in requests expire after five minutes.** Leave the login page open
>   too long and Cognito cancels the request and shows `Something went wrong`.
>   Starting again from `/login.php` fixes it.
> - **The browser keeps a one-hour session cookie.** After signing in, users
>   are silently signed back in for an hour without a prompt — which makes
>   "sign out and try again" demos confusing. Use a private window when
>   demonstrating sign-in, and note that the cookie's hour does **not** reset
>   on each use.
>
> Managed login also requires **TLS 1.2**, which CloudFront and the ALB both
> satisfy with the settings in this runbook.

### 6f. Record the values

Copy into the Secrets Manager secret from [step 4](#4-secrets-manager) — the one
in **us-east-2**, since that is the Region the instances read it from:

| Secret key | Where to find it |
|---|---|
| `cognito_user_pool_id` | pool overview, `us-east-2_XXXXXXXXX` |
| `cognito_client_id` | App clients → `letsvote-web` |
| `cognito_client_secret` | same page, *Show client secret* |
| `cognito_domain` | `letsvote-auth.auth.us-east-2.amazoncognito.com` — hostname only, no `https://`, no trailing slash |

There is no `cognito_region` field. The application takes the Cognito Region
from `AWS_REGION` in `deploy/userdata.sh`, which writes it into
`/etc/letsvote/config.ini` as `[cognito] region`. That is why `AWS_REGION` must
be `us-east-2`: it sets the token issuer that `src/Jwt.php` checks, not just the
Region used to read the secret.

> **`redirect_mismatch` is the single most common thing to get stuck on here.**
> The callback URL must match character for character: scheme, host, path, and
> no trailing slash.

### What the app does with all this

Worth walking through with the class — it maps 1:1 onto AWS's documented
contract, and all of it is already implemented:

| Step | Endpoint | Code |
|---|---|---|
| Send user to sign in | `GET /oauth2/authorize` | `src/Cognito.php` → `authorizeUrl()` |
| Send user to sign **up** | `GET /signup` | same, `?new=1` |
| Swap code for tokens | `POST /oauth2/token` | `exchangeCode()` |
| Verify the ID token | JWKS at `cognito-idp.us-east-2.amazonaws.com/us-east-2_XXXXXXXXX/.well-known/jwks.json` | `src/Jwt.php` |
| Sign out | `GET /logout?client_id=…&logout_uri=…` | `logoutUrl()` |

PKCE is on: AWS supports only `code_challenge_method=S256`, which is what the
code sends, and `code_verifier` is accepted alongside a client secret.

> ### What you cannot change later
> AWS lists these as fixed once created — all three are decided in 6a:
> **client secret** (needs a new app client), **sign-in options** and
> **required attributes** (both need a new user pool). Also fixed: user pool ID
> and username case sensitivity. The user pool *name* can now be changed, which
> it could not before.

*Docs: [Create an application in the console](https://docs.aws.amazon.com/cognito/latest/developerguide/getting-started-user-pools-application.html) ·
[Settings you can't change](https://docs.aws.amazon.com/cognito/latest/developerguide/cognito-user-pool-updating.html) ·
[Feature plans](https://docs.aws.amazon.com/cognito/latest/developerguide/cognito-sign-in-feature-plans.html) ·
[Managed login endpoints](https://docs.aws.amazon.com/cognito/latest/developerguide/managed-login-endpoints.html) ·
[Token endpoint](https://docs.aws.amazon.com/cognito/latest/developerguide/token-endpoint.html)*

## 7. IAM role for the instances

IAM → *Roles* → *Create role* → **AWS service** → **EC2**.

Attach the managed policy **`AmazonSSMManagedInstanceCore`** — this is what
gives Session Manager access to instances with no SSH port.

Add an inline policy `read-letsvote-secret`:

```json
{
  "Version": "2012-10-17",
  "Statement": [{
    "Effect": "Allow",
    "Action": "secretsmanager:GetSecretValue",
    "Resource": "arn:aws:secretsmanager:us-east-2:860977520909:secret:letsvote/app-config-*"
  }]
}
```

The `-*` is required: Secrets Manager appends six random characters to every
secret ARN.

Role name: `letsvote-ec2-role`.

## 8. ACM certificate

**You need two certificates, in two different Regions.** An ALB can only use a
certificate from its own Region, and CloudFront only accepts certificates from
us-east-1. Because this build runs in us-east-2, no single certificate can
serve both.

| | Region | Names on the certificate | Used by |
|---|---|---|---|
| **Cert A** | **us-east-2** (Ohio) | `origin.letsvotes.com` | the ALB, [step 11](#11-application-load-balancer) |
| **Cert B** | **us-east-1** (N. Virginia) | `letsvotes.com`, `www.letsvotes.com` | CloudFront, [step 14](#14-cloudfront) |

For each one: Certificate Manager → **check the Region selector in the console
top-right before you click anything** → *Request* → **Public certificate** →
enter the names above → validation method **DNS**.

Then, on each certificate's detail page, click **Create records in Route 53**.
Both certificates validate out of the same hosted zone, and validation takes a
few minutes. A certificate stuck in *Pending validation* means the DNS record
was never created — go back and click the button.

> **The mistake this prevents.** Build everything in one Region out of habit
> and step 11 shows an empty certificate dropdown (no us-east-2 certificate
> exists), or step 14 refuses your certificate (it isn't in us-east-1). Neither
> error names the Region as the cause.

> **Why `origin.letsvotes.com`?** CloudFront validates the origin's
> certificate against the origin domain name you type in. Point it straight at
> `letsvote-alb-123.us-east-2.elb.amazonaws.com` and the certificate for
> `letsvotes.com` won't match — you get a 502. Giving the ALB its own name
> that is *on the certificate* is the documented fix. AWS states it directly:
> using HTTPS to an ALB origin requires a certificate "that matches the domain
> name that is routed to your Application Load Balancer."

## 9. Launch template

EC2 → *Launch templates* → *Create*.

- Name `letsvote-webapp-lt`
- AMI **Amazon Linux 2023** (x86_64), instance type `t3.micro`
- Key pair: **Do not include** (Session Manager instead)
- Network settings: **Do not specify a subnet** (the ASG chooses), security
  group **`letsvote-webapp-sg`**
- Advanced details → IAM instance profile **`letsvote-ec2-role`**
- Advanced details → **Metadata version: V2 only (token required)**
- Advanced details → **User data**: paste `deploy/userdata.sh` as-is. The four
  variables at the top are already filled in for this build:

  | Variable | Value |
  |---|---|
  | `APP_REPO` | `https://github.com/jayakuma998/letsvote.git` |
  | `SECRET_ID` | `letsvote/app-config` |
  | `AWS_REGION` | `us-east-2` |
  | `BASE_URL` | `https://letsvotes.com` |

The repository must be **public**, because the boot script clones it over
HTTPS with no credentials — an SSH remote you can push to is not enough. If you
keep it private instead, upload a zip to S3, grant the role `s3:GetObject` on
it, and use the alternative command already commented into the script.

AL2023 ships PHP 8.1–8.5; the script installs the default `php` package and
then **hard-fails the boot if PHP is older than 8.1**, so a bad AMI shows up
immediately instead of as random 500s.

## 10. Target group

EC2 → *Target groups* → *Create*.

- Target type **Instances**, protocol **HTTP** port **80**, VPC `letsvote-vpc`
- Name `letsvote-tg`
- Health check path **`/health.php`**
- Advanced: healthy threshold `2`, unhealthy `3`, timeout `5`s, interval `15`s,
  success codes `200`
- **Register no targets by hand** — the Auto Scaling group does it

## 11. Application Load Balancer

EC2 → *Load balancers* → *Create load balancer* → under **Application Load
Balancer**, *Create*.

- **Load balancer name** `letsvote-alb`; **Scheme: Internet-facing**;
  **IP address type: IPv4**
- **Network mapping** → VPC `letsvote-vpc`; select **us-east-2a →
  public-subnet-01** and **us-east-2b → public-subnet-02**
- **Security groups**: `letsvote-lb-sg` (remove the preselected default group)
- **Listeners and routing**: change the default listener to **HTTPS : 443**,
  default action **Forward to** `letsvote-tg`
- **Secure listener settings** (this section only appears once you add an HTTPS
  listener): **Default SSL/TLS certificate** → **From ACM** → **Cert A**, the
  **us-east-2** certificate for `origin.letsvotes.com` from
  [step 8](#8-acm-certificate). Leave the recommended security policy.
  An empty dropdown here means Cert A was requested in the wrong Region.
- After creating, add a second listener **HTTP : 80** → **Redirect to HTTPS**,
  port 443, `HTTP_301`

Copy the ALB DNS name.

## 12. Auto Scaling group

EC2 → *Auto Scaling groups* → *Create an Auto Scaling group*.

1. **Choose launch template or configuration**: name `letsvote-asg`, launch
   template `letsvote-webapp-lt`, version **Latest**.
2. **Choose instance launch options**: VPC `letsvote-vpc`; under
   **Availability Zones and subnets** pick **both** `webapp-subnet-01` and
   `webapp-subnet-02` — one subnet means no multi-AZ resilience.
3. **Integrate with other services**:
   - **Load balancing** → *Attach to an existing load balancer* → *Choose from
     your load balancer target groups* → `letsvote-tg`
   - **Health checks** → turn on **Elastic Load Balancing health checks**
   - **Health check grace period**: `300` seconds — user data has to install
     packages before the instance can answer
4. **Configure group size and scaling**: desired `2`, min `2`, max `4`.
   Optionally add a **Target tracking scaling policy** on *Average CPU
   utilization* at 60%.
5. **Review** → *Create Auto Scaling group*.

Two instances boot. Watch the target group until both read **healthy**. If they
don't, jump to [troubleshooting](#19-when-it-does-not-work).

## 13. Load the database schema

The instances are private, so use Session Manager rather than SSH:
EC2 → *Instances* → select one → *Connect* → **Session Manager** → *Connect*.

```bash
sudo -i
cd /var/www/letsvote

# Master credentials, one time only, to create the schema and the app account.
mysql -h database-1.c3q484mw8u8f.us-east-2.rds.amazonaws.com -u admin -p < sql/schema.sql
mysql -h database-1.c3q484mw8u8f.us-east-2.rds.amazonaws.com -u admin -p letsvote < sql/seed_candidates.sql

# Now the limited account the application actually uses.
# Use the same password you put in db_pass in Secrets Manager.
mysql -h database-1.c3q484mw8u8f.us-east-2.rds.amazonaws.com -u admin -p <<'SQL'
CREATE USER 'letsvote_app'@'172.16.%.%' IDENTIFIED BY 'THE-DB-PASS-FROM-SECRETS-MANAGER';
GRANT SELECT, INSERT, UPDATE, DELETE ON letsvote.* TO 'letsvote_app'@'172.16.%.%';
FLUSH PRIVILEGES;
SQL

# Prove the app reaches both databases:
curl -s "http://localhost/health.php?deep=1"
```

Expect `"database": "ok"`. Replication copies the schema to the replica
automatically — never run DDL against a replica.

Make yourself an admin **after your first Cognito sign-in** (the user row only
exists once you have logged in once):

```sql
UPDATE users SET is_admin = 1 WHERE email = 'you@example.com';
```

## 14. CloudFront

Console: <https://console.aws.amazon.com/cloudfront/v4/home> → *Create
distribution*.

- **Origin domain**: `origin.letsvotes.com` — type it manually; you create
  that DNS record in step 15 and CloudFront accepts a name that doesn't resolve
  yet
- **Protocol: HTTPS only**, minimum origin SSL **TLSv1.2**
- **Viewer protocol policy: Redirect HTTP to HTTPS**
- **Allowed HTTP methods: GET, HEAD, OPTIONS, PUT, POST, PATCH, DELETE** —
  without POST nobody can sign in or vote
- **Cache policy: `CachingDisabled`**
- **Origin request policy: `AllViewer`**
- **Alternate domain names (CNAMEs)**: `letsvotes.com`,
  `www.letsvotes.com`
- **Custom SSL certificate**: **Cert B**, the **us-east-1** certificate for
  `letsvotes.com` + `www.letsvotes.com` from [step 8](#8-acm-certificate). This
  is a *different* certificate from the one on the ALB — CloudFront will not
  list Cert A at all

> **The cache policy and origin request policy are the two settings that will
> break your app if you get them wrong.** With the default `CachingOptimized`,
> CloudFront strips cookies and caches HTML: one student's logged-in page gets
> served to the whole class and CSRF tokens break. A dynamic PHP app must
> forward everything and cache nothing. `AllViewer` is also what forwards the
> `Host` header, which AWS documents as **required** when CloudFront talks
> HTTPS to an ALB origin. (Giving `/assets/*` its own cached behaviour later is
> a good follow-up exercise.)

Because the ALB is internet-facing, it is reachable directly from the internet
right now — which means CloudFront and WAF can be bypassed. **Step 16 closes
that hole and is not optional.**

*Docs: [Restrict access to ALBs](https://docs.aws.amazon.com/AmazonCloudFront/latest/DeveloperGuide/restrict-access-to-load-balancer.html) ·
[Create a distribution](https://docs.aws.amazon.com/AmazonCloudFront/latest/DeveloperGuide/distribution-web-creating-console.html)*

## 15. Route 53

Route 53 → *Hosted zones* → your domain → *Create record*, three times:

| Record | Type | Value |
|---|---|---|
| `letsvotes.com` | A – **Alias** | Alias to CloudFront distribution |
| `www.letsvotes.com` | A – **Alias** | Alias to CloudFront distribution |
| `origin.letsvotes.com` | A – **Alias** | Alias to Application Load Balancer (us-east-2) |

Alias records are free and point straight at the AWS resource; a CNAME cannot
be used at the zone apex.

Give DNS a few minutes, then open `https://letsvotes.com`.

## 16. Lock the ALB to CloudFront

**Do not skip this.** An internet-facing ALB has a publicly resolvable DNS
name, so at this point anyone can hit `origin.letsvotes.com` directly and
walk straight past CloudFront *and* the WAF rules you add in step 17. Rate
limiting, managed rule groups, TLS policy — all bypassed.

AWS's documented fix is a shared secret header that only CloudFront sends,
plus a load balancer rule that refuses everything else.

1. **CloudFront** → your distribution → *Origins* → edit the origin → **Add
   custom header**: name `X-Origin-Verify`, value a long random string. Treat
   it like a password.
2. **EC2 → Load balancers** → `letsvote-alb` → the **HTTPS:443** listener →
   *Manage rules*:
   - Add a rule: **Condition = Http header** `X-Origin-Verify` equals your
     value → **Action = Forward to** `letsvote-tg`, priority 1
   - Edit the **default** rule: delete the forward action, replace with
     **Return fixed response**, response code **403**, body `Access denied`
3. Optionally also narrow `letsvote-lb-sg` inbound 443 from `0.0.0.0/0` to the
   **`com.amazonaws.global.cloudfront.origin-facing`** managed prefix list,
   which blocks non-CloudFront traffic at layer 3/4.

Test: `https://letsvotes.com` works; `https://origin.letsvotes.com`
returns `403 Access denied`.

## 17. WAF

> **AWS is rolling out a new WAF console** where a web ACL is called a
> **"protection pack"**. You may land in either one.

**New console** (<https://console.aws.amazon.com/wafv2-pro>):

1. **Resources & protection packs (web ACLs)** → *Add protection pack (web ACL)*
2. **App category**, then **Traffic source: Web**
3. **Resources to protect** → *Add resources* → **Amazon CloudFront
   distributions** → your distribution
4. **Choose initial protections** → **Recommended**, or **You build it** to add
   rule groups by hand
5. Under **Rule configuration**, set **Default rate limits**
6. Name it `letsvote-web-acl` → *Add protection pack*

**Standard console** (<https://console.aws.amazon.com/wafv2/homev2>):

1. *Create web ACL*, name `letsvote-web-acl`
2. **Resource type: Amazon CloudFront distributions** (Region is hard-coded to
   us-east-1 for CloudFront)
3. *Add AWS resources* → your distribution
4. *Add rules* → *Add managed rule groups*:
   - `AWSManagedRulesCommonRuleSet`
   - `AWSManagedRulesKnownBadInputsRuleSet`
   - `AWSManagedRulesAmazonIpReputationList`
5. Add your own **rate-based rule**: block an IP over `1000` requests / 5
   minutes. Consider a second, much lower rate rule scoped to `/vote.php`.
6. Default action **Allow** → create.

Attach at **CloudFront (Global)** scope, not the ALB — blocking at the edge is
cheaper and faster.

> **Start every rule in Count mode**, watch the sampled requests for a day,
> then switch to Block. AWS explicitly warns to tune before enforcing; going
> straight to Block often locks out your own class.

Shield Standard already protects CloudFront and the ALB at no cost. Nothing to
click. (Again: not Shield Advanced.)

## 18. Teardown, in this order

Reverse dependency order, or deletions fail:

1. WAF web ACL / protection pack (disassociate first)
2. CloudFront distribution — *Disable*, wait for deployment to finish, then
   *Delete*. 15–20 minutes and it cannot be rushed.
3. Route 53 records (keep the hosted zone if keeping the domain)
4. Auto Scaling group — set desired and min to `0` first, then delete
5. ALB, then the target group
6. RDS **read replica first**, then the primary
7. **NAT gateway**, then **release the Elastic IP** — an unattached EIP is
   still billed
8. Launch template, IAM role, Secrets Manager secret (7-day minimum recovery
   window), Cognito user pool
9. Subnets, route tables, internet gateway, then the VPC

Check *Billing → Bills* the next day for anything still accruing.

## 19. When it does not work

| Symptom | Cause | Fix |
|---|---|---|
| Targets stuck **unhealthy** | user data failed | Session Manager in, read `/var/log/cloud-init-output.log`, then `curl localhost/health.php` |
| **Create read replica** greyed out | backup retention is 0 | Modify `database-1`, set backup retention ≥ 1 day, apply, then retry |
| `502 Bad Gateway` from CloudFront | origin certificate mismatch | Origin must be `origin.letsvotes.com` and that name must be on the ACM certificate |
| `503` from the ALB | no healthy targets | Check the target group; `letsvote-webapp-sg` must allow 80 **from `letsvote-lb-sg`** |
| Cognito `redirect_mismatch` | callback URL differs | Must equal `https://letsvotes.com/callback.php` exactly |
| Logged out at random / "sign-in link expired" | CloudFront not forwarding cookies | Cache policy `CachingDisabled`, origin request policy `AllViewer` |
| Everyone sees the same logged-in page | CloudFront caching HTML | Same as above, then invalidate `/*` |
| Sign-up shows an error but the user exists | Cognito can't send the verification email | Set attribute verification to **Send email message**; check the 50/day Cognito cap |
| `Could not complete your sign-in` | instance can't reach the token endpoint | Check NAT gateway, `webapp-rt`, outbound 443 on `letsvote-webapp-sg`; read `/var/log/httpd/letsvote_error.log` |
| `Missing required config value 'db.host'` | Secrets Manager read failed at boot | Check the instance profile and the `-*` on the secret ARN in the IAM policy. Also confirm the secret is in **us-east-2** and the policy ARN says `us-east-2` — a secret in another Region is invisible to the instances |
| No certificate to choose in the ALB listener dropdown ([step 11](#11-application-load-balancer)) | the certificate was requested in us-east-1 | An ALB only lists certificates from its own Region. Request **Cert A** again in **us-east-2** ([step 8](#8-acm-certificate)) |
| CloudFront rejects your certificate ([step 14](#14-cloudfront)) | the certificate is in us-east-2 | CloudFront accepts custom certificates only from **us-east-1**. That is **Cert B**, and it is a different certificate from the ALB's |
| Sign-in fails after a correct-looking Cognito setup | pool Region and `AWS_REGION` disagree | The pool ID must start `us-east-2_` and `AWS_REGION` in the user data must be `us-east-2`. `AWS_REGION` sets the token issuer `src/Jwt.php` validates, so a mismatch fails verification even though every URL looks right |
| `ID token issuer mismatch` after a successful Cognito login | user pool **Issuer type** was switched to *Updated* | Set it back to **Original** on the pool's *Domain* page. *Updated* issues `iss` as `issuer-cognito-idp.…`, which `Cognito::issuer()` does not build. See [step 6d](#6d-create-the-managed-login-domain) |
| `redirect_mismatch` although the callback URL looks right | the domain prefix in the secret isn't the one the pool actually has | The console auto-creates a domain with a generated prefix. Compare `cognito_domain` in Secrets Manager against the pool's *Domain* page character for character |
| `SQLSTATE[HY000] [2002]` | can't reach RDS | `letsvote-db-sg` must allow 3306 from `letsvote-webapp-sg`; RDS must use `letsvote-subnet-group` |
| WAF blocks your own class | rules went straight to Block | Set the rule group to **Count**, review sampled requests, re-enable gradually |
| `origin.letsvotes.com` serves the site instead of `403` | step 16 not done, or the header value doesn't match | Compare the CloudFront origin custom header with the ALB listener rule condition, character for character |
| Everything returns `403 Access denied` | the ALB default rule is catching CloudFront too | The header rule must have a lower priority number (evaluated first) than the default fixed-response rule |

Application errors land in `/var/log/httpd/letsvote_error.log` on each
instance. Shipping those to CloudWatch Logs with the CloudWatch agent is a good
follow-up exercise — with two instances, tailing logs by hand gets old fast.

## 20. Things to try once it works

- Refresh and watch the *Served by* line in the footer change — that is the ALB
  alternating between AZ1 and AZ2.
- Terminate one instance. The ASG replaces it and the site never goes down.
  This is the entire reason for the diagram.
- Open `/results.php` and show in `src/Election.php` that every query on that
  page went to the **read replica**.
- Try to vote twice from two browser tabs and watch the database refuse the
  second one.
- Hit `https://origin.letsvotes.com` directly and get the `403` from
  [step 16](#16-lock-the-alb-to-cloudfront).
