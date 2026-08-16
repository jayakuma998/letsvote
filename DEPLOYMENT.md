# Deploying LetsVote on AWS — manual console runbook

Every step is done **by hand in the AWS console**, in this order. The order
matters: each step produces an ID the next step needs.

Region for the whole build: **us-east-1**. Replace `letsvote.example` with the
domain you actually register.

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

**The domain.** `letsvote.com` has been registered for years. In Route 53 →
*Registered domains* → *Register domain*, try `letsvote.click` / `.link` /
`.info` (a few dollars a year) or `letsvote-class.com`. Do this first —
ACM validation in step 8 depends on it.

**Cost.** This is not free tier. Rough us-east-1 monthly estimate:

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
| `public-subnet-01` | us-east-1a | `172.16.0.0/24` | ALB |
| `public-subnet-02` | us-east-1b | `172.16.1.0/24` | ALB + NAT gateway |
| `webapp-subnet-01` | us-east-1a | `172.16.2.0/24` | EC2 |
| `webapp-subnet-02` | us-east-1b | `172.16.3.0/24` | EC2 |
| `database-subnet-01` | us-east-1a | `172.16.4.0/24` | RDS primary |
| `database-subnet-02` | us-east-1b | `172.16.5.0/24` | RDS read replica |

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

**Checkpoint.** The database subnets now have no path to or from the internet.
That is the point of a separate data tier.

## 3. Security groups

VPC → *Security groups*. Create all three empty first, then add rules — they
reference each other.

**`webapp-lb-sg`** (on the ALB)

| Direction | Type | Port | Source/Destination |
|---|---|---|---|
| Inbound | HTTPS | 443 | `0.0.0.0/0` (tightened in [step 16](#16-lock-the-alb-to-cloudfront)) |
| Inbound | HTTP | 80 | `0.0.0.0/0` (only to redirect to 443) |
| Outbound | HTTP | 80 | `webapp-sg` |

**`webapp-sg`** (on the EC2 instances)

| Direction | Type | Port | Source/Destination |
|---|---|---|---|
| Inbound | HTTP | 80 | **`webapp-lb-sg`** — a security group, not a CIDR |
| Outbound | HTTPS | 443 | `0.0.0.0/0` (Cognito, Secrets Manager, dnf) |
| Outbound | MySQL | 3306 | `db-sg` |

**`db-sg`** (on RDS)

| Direction | Type | Port | Source/Destination |
|---|---|---|---|
| Inbound | MySQL | 3306 | **`webapp-sg`** |

No SSH rule anywhere. You reach instances with **SSM Session Manager** — no
open port, no key pair, no bastion. This is also what makes the
`X-Forwarded-For` header trustworthy: nothing on the internet can reach an
instance directly to forge it.

## 4. Secrets Manager

Secrets Manager → *Store a new secret* → **Other type of secret** →
*Plaintext*. Paste this and fill in the `TBD`s in steps 5 and 6.

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

1. RDS → *Subnet groups* → *Create*: `letsvote-db-subnet-group`, VPC
   `letsvote-vpc`, AZs `us-east-1a` + `us-east-1b`, subnets
   `database-subnet-01` and `database-subnet-02`.

2. RDS → *Create database* → **Standard create** → **MySQL 8.0**
   - Template **Dev/Test** (Free tier will not let you add a read replica)
   - DB instance identifier `primary-db`
   - Master username `admin`; save this password in your own notes — it is
     **not** the application password
   - `db.t4g.micro`, 20 GiB gp3
   - Availability: Single-AZ is fine for class; Multi-AZ doubles the cost
   - Connectivity: VPC `letsvote-vpc`, the subnet group above,
     **Public access = No**, security group **`db-sg`**
   - Additional configuration → Initial database name: **leave blank**
     (`sql/schema.sql` creates it)
   - **Backup → keep automated backups ON with retention ≥ 1 day.**

   > **This one is a hard requirement, not advice.** AWS: *"you must enable
   > automatic backups on the source DB instance by setting the backup
   > retention period to a value other than 0."* With retention at 0 the
   > **Create read replica** action is unavailable and step 3 below fails.

3. Wait for **Available** → select `primary-db` → *Actions* → **Create read
   replica**
   - DB instance identifier `readreplica-db`
   - **Availability Zone `us-east-1b`**, same instance class,
     **Public access = No**, security group `db-sg`
   - Keep it in the **same VPC** as the source — AWS warns that a replica in a
     different VPC can hit CIDR overlap and become unstable

4. Copy both endpoints into the Secrets Manager secret (`db_host`,
   `db_host_read`).

A read replica is **read-only** — MySQL rejects writes against it. That is
exactly why `Db::read()` is used only for tallies.

*Docs: [Creating a read replica](https://docs.aws.amazon.com/AmazonRDS/latest/UserGuide/USER_ReadRepl.Create.html)*

## 6. Cognito user pool

Console: <https://console.aws.amazon.com/cognito/v2/idp/user-pools>

> **The Cognito console was redesigned.** The old "Confidential client" radio
> button is gone, replaced by an **Application type** chooser. And user pools
> now have a **feature plan**. Both are covered below.

### 6a. Create the user pool

Cognito → **User pools** → *Create user pool*.

- **Feature plan**: the default for new pools is **Essentials**. Keep it —
  10,000 MAU are free, and *managed login* (the modern sign-in UI) requires
  Essentials or Plus. Choosing **Lite** is also fine and slightly cheaper past
  the free tier, but then you get the **classic hosted UI** only.
- Sign-in options: **Email**
- Password policy: Cognito defaults are fine
- MFA: **No MFA** for class (or optional TOTP to demo it)
- Self-service sign-up: **enabled**
- Attribute verification: **Send email message, verify email address**
- Required attributes: `email`, plus add **`name`**
- Email provider: **Send email with Cognito** — capped at **50 emails/day**.
  Fine for a class, nothing more. Real traffic needs SES.
- User pool name: `letsvote-users`

> Keep **"Allow Cognito to automatically send messages to verify and confirm"**
> on. AWS documents that if you instead confirm users as an administrator, the
> login pages show an error after sign-up even though the user was created.

### 6b. Create the domain

In the pool → **Domain** (or *App integration* → *Domain*) → *Create Cognito
domain* → prefix `letsvote-auth`. Result:
`letsvote-auth.auth.us-east-1.amazoncognito.com`.

- **Branding version**: choose **Managed login** (Essentials) or
  **Hosted UI (classic)** (any plan). **Either works with this app** — AWS
  documents that all endpoint paths except `/passkeys/add` are shared between
  the two branding versions.
- You can't use `aws`, `amazon`, or `cognito` in the prefix.
- A prefix domain takes up to 60 seconds to come up.

### 6c. Create the app client

In the pool → **App clients** → *Create app client*.

- **Application type: `Traditional web application`**
  This is the replacement for the old "Confidential client" option, and it is
  what generates the **client secret** that the PHP code uses for
  `client_secret_basic` authentication at the token endpoint.
- Name: `letsvote-web`
- **Return URL** (a.k.a. callback URL): `https://letsvote.example/callback.php`
- Then open the client's settings and confirm/complete:
  - **Allowed callback URLs**: `https://letsvote.example/callback.php`
    *(optionally also `http://localhost:8000/callback.php` — Cognito permits
    plain `http` for `localhost` only)*
  - **Allowed sign-out URLs**: `https://letsvote.example/`
  - **OAuth 2.0 grant types**: **Authorization code grant** only. Never
    *Implicit* — it puts tokens in the browser URL bar.
  - **OpenID Connect scopes**: `openid`, `email`, `profile`

Copy the **user pool ID**, **client ID**, **client secret** and **domain** into
the Secrets Manager secret.

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
| Verify the ID token | JWKS at `cognito-idp.…/.well-known/jwks.json` | `src/Jwt.php` |
| Sign out | `GET /logout?client_id=…&logout_uri=…` | `logoutUrl()` |

PKCE is on: AWS supports only `code_challenge_method=S256`, which is what the
code sends, and `code_verifier` is accepted alongside a client secret.

*Docs: [App clients](https://docs.aws.amazon.com/cognito/latest/developerguide/cognito-user-pools-app-idp-settings.html) ·
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
    "Resource": "arn:aws:secretsmanager:us-east-1:YOUR-ACCOUNT-ID:secret:letsvote/app-config-*"
  }]
}
```

The `-*` is required: Secrets Manager appends six random characters to every
secret ARN.

Role name: `letsvote-ec2-role`.

## 8. ACM certificate

Certificate Manager, **us-east-1**. CloudFront only accepts certificates from
this Region, and our ALB is here too, so one certificate covers both.

*Request* → **Public certificate**, three names:

- `letsvote.example`
- `www.letsvote.example`
- `origin.letsvote.example` ← the name CloudFront uses to reach the ALB

Validation method **DNS**. If the domain is in Route 53, click *Create records
in Route 53* and it validates in minutes.

> **Why `origin.letsvote.example`?** CloudFront validates the origin's
> certificate against the origin domain name you type in. Point it straight at
> `letsvote-alb-123.us-east-1.elb.amazonaws.com` and the certificate for
> `letsvote.example` won't match — you get a 502. Giving the ALB its own name
> that is *on the certificate* is the documented fix. AWS states it directly:
> using HTTPS to an ALB origin requires a certificate "that matches the domain
> name that is routed to your Application Load Balancer."

## 9. Launch template

EC2 → *Launch templates* → *Create*.

- Name `letsvote-webapp-lt`
- AMI **Amazon Linux 2023** (x86_64), instance type `t3.micro`
- Key pair: **Do not include** (Session Manager instead)
- Network settings: **Do not specify a subnet** (the ASG chooses), security
  group **`webapp-sg`**
- Advanced details → IAM instance profile **`letsvote-ec2-role`**
- Advanced details → **Metadata version: V2 only (token required)**
- Advanced details → **User data**: paste `deploy/userdata.sh` after editing
  the four variables at the top (`APP_REPO`, `SECRET_ID`, `AWS_REGION`,
  `BASE_URL`)

Push this repo to GitHub first so `APP_REPO` resolves — or for a private repo,
upload a zip to S3, grant the role `s3:GetObject` on it, and use the
alternative command already commented into the script.

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
- **Network mapping** → VPC `letsvote-vpc`; select **us-east-1a →
  public-subnet-01** and **us-east-1b → public-subnet-02**
- **Security groups**: `webapp-lb-sg` (remove the preselected default group)
- **Listeners and routing**: change the default listener to **HTTPS : 443**,
  default action **Forward to** `letsvote-tg`
- **Secure listener settings** (this section only appears once you add an HTTPS
  listener): **Default SSL/TLS certificate** → **From ACM** → the step 8
  certificate. Leave the recommended security policy.
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
mysql -h primary-db.XXXX.us-east-1.rds.amazonaws.com -u admin -p < sql/schema.sql
mysql -h primary-db.XXXX.us-east-1.rds.amazonaws.com -u admin -p letsvote < sql/seed_candidates.sql

# Now the limited account the application actually uses.
# Use the same password you put in db_pass in Secrets Manager.
mysql -h primary-db.XXXX.us-east-1.rds.amazonaws.com -u admin -p <<'SQL'
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

- **Origin domain**: `origin.letsvote.example` — type it manually; you create
  that DNS record in step 15 and CloudFront accepts a name that doesn't resolve
  yet
- **Protocol: HTTPS only**, minimum origin SSL **TLSv1.2**
- **Viewer protocol policy: Redirect HTTP to HTTPS**
- **Allowed HTTP methods: GET, HEAD, OPTIONS, PUT, POST, PATCH, DELETE** —
  without POST nobody can sign in or vote
- **Cache policy: `CachingDisabled`**
- **Origin request policy: `AllViewer`**
- **Alternate domain names (CNAMEs)**: `letsvote.example`,
  `www.letsvote.example`
- **Custom SSL certificate**: the step 8 certificate

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
| `letsvote.example` | A – **Alias** | Alias to CloudFront distribution |
| `www.letsvote.example` | A – **Alias** | Alias to CloudFront distribution |
| `origin.letsvote.example` | A – **Alias** | Alias to Application Load Balancer (us-east-1) |

Alias records are free and point straight at the AWS resource; a CNAME cannot
be used at the zone apex.

Give DNS a few minutes, then open `https://letsvote.example`.

## 16. Lock the ALB to CloudFront

**Do not skip this.** An internet-facing ALB has a publicly resolvable DNS
name, so at this point anyone can hit `origin.letsvote.example` directly and
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
3. Optionally also narrow `webapp-lb-sg` inbound 443 from `0.0.0.0/0` to the
   **`com.amazonaws.global.cloudfront.origin-facing`** managed prefix list,
   which blocks non-CloudFront traffic at layer 3/4.

Test: `https://letsvote.example` works; `https://origin.letsvote.example`
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
| **Create read replica** greyed out | backup retention is 0 | Modify `primary-db`, set backup retention ≥ 1 day, apply, then retry |
| `502 Bad Gateway` from CloudFront | origin certificate mismatch | Origin must be `origin.letsvote.example` and that name must be on the ACM certificate |
| `503` from the ALB | no healthy targets | Check the target group; `webapp-sg` must allow 80 **from `webapp-lb-sg`** |
| Cognito `redirect_mismatch` | callback URL differs | Must equal `https://letsvote.example/callback.php` exactly |
| Logged out at random / "sign-in link expired" | CloudFront not forwarding cookies | Cache policy `CachingDisabled`, origin request policy `AllViewer` |
| Everyone sees the same logged-in page | CloudFront caching HTML | Same as above, then invalidate `/*` |
| Sign-up shows an error but the user exists | Cognito can't send the verification email | Set attribute verification to **Send email message**; check the 50/day Cognito cap |
| `Could not complete your sign-in` | instance can't reach the token endpoint | Check NAT gateway, `webapp-rt`, outbound 443 on `webapp-sg`; read `/var/log/httpd/letsvote_error.log` |
| `Missing required config value 'db.host'` | Secrets Manager read failed at boot | Check the instance profile and the `-*` on the secret ARN in the IAM policy |
| `SQLSTATE[HY000] [2002]` | can't reach RDS | `db-sg` must allow 3306 from `webapp-sg`; RDS must use `letsvote-db-subnet-group` |
| WAF blocks your own class | rules went straight to Block | Set the rule group to **Count**, review sampled requests, re-enable gradually |
| `origin.letsvote.example` serves the site instead of `403` | step 16 not done, or the header value doesn't match | Compare the CloudFront origin custom header with the ALB listener rule condition, character for character |
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
- Hit `https://origin.letsvote.example` directly and get the `403` from
  [step 16](#16-lock-the-alb-to-cloudfront).
