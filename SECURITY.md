# Secure Dice Security Model

## What the application protects

Secure Dice is designed for anonymous public rolling with recipient-controlled email delivery. Its security boundaries are:

- Result IDs and private consent links are 256-bit or 128-bit random capabilities that cannot feasibly be enumerated.
- Canonical result JSON is authenticated with a server-secret HMAC before it is accepted as genuine. The public SHA-256 value remains an additional corruption check.
- The application inserts result rows without a write-back path. Stored HMACs expose direct database tampering when a result is read. DreamHost Shared MySQL cannot create an immutability trigger.
- Email addresses and queued payloads are encrypted at rest. Address lookup, source identifiers, and rate-limit buckets use keyed fingerprints rather than plaintext.
- Confirmation and recovery links are single-use, expire after 24 hours, and require an explicit CSRF-protected POST before they change state. Email scanners can safely open the initial GET.
- Management and unsubscribe changes require both an unguessable capability and a same-site CSRF token.
- A result can be queued only once for a given recipient. Replays do not send another copy or consume that recipient's delivery allowance.
- Browser responses use a restrictive Content Security Policy, clickjacking protection, MIME-sniffing protection, private/no-store handling for sensitive pages, and strict cookie-only sessions.

## Enumeration resistance

Enrollment and result-request pages return generic responses and do not disclose whether an address is active, paused, revoked, unknown, or rate-limited. Delivered result email contains aggregate recipient counts, never names or addresses.

Aggregate counts necessarily reveal limited group-level information to an opted-in recipient included in the same request. Mixed opted-in/non-opted-in requests are therefore separately limited to 20 per source IP per hour. This is a deliberate usability/privacy tradeoff; it is not intended to provide anonymity against repeated, controlled differential probing by someone who already controls an opted-in address.

## Rate limits

Limits apply independently to enrollment sources and addresses, token endpoints, management and unsubscribe lookups, result-request sources, mixed-recipient requests, each recipient's selected delivery mode, and global SMTP output. Identifiers are stored only as secret-keyed hashes. See `README.md` for the operational values.

Rate limiting uses the web server's `REMOTE_ADDR`. A reverse proxy must be configured at the web-server layer to replace that value only for trusted proxy connections. Secure Dice deliberately does not trust client-supplied forwarding headers.

## Trust assumptions

The application assumes:

- HTTPS terminates at a trusted web server or reverse proxy.
- `SECUREDICE_SECRET`, the SMTP password, the MySQL database, backups, and the hosting account remain private.
- The host's PHP runtime, operating system, mail account, and random-number generator are trustworthy.

The HMAC authenticates a record to this Secure Dice installation. It is not a public-key signature and does not prove a result independently of the server. An attacker who controls both the database and `SECUREDICE_SECRET`, or who can execute code as the application, can forge records.

## Replay and recovery behavior

- Opt-in confirmation and settings-recovery challenges can be consumed once.
- Recovery revokes the previous settings link.
- Unsubscribe revokes the recipient, all settings links, outstanding challenges, and pending messages.
- Re-opting-in requires a new confirmation.
- Replacing or losing `SECUREDICE_SECRET` invalidates result authentication and makes encrypted recipient data unreadable. Restore the secret and database from the same backup set.

## Security testing

The automated suite covers malformed, expired, tampered, cross-purpose, and replayed tokens; forged CSRF values; hashed rate-limit identifiers and boundary resets; forged result data with a recomputed public digest; immutable storage; permanent result-email replay claims; and required browser defenses.

Run all checks with:

```shell
for test_file in tests/*-test.php; do php "$test_file"; done
```

## Reporting a vulnerability

Please report suspected vulnerabilities privately to `bblackmoor@blackgate.net`. Include the affected version, reproduction steps, and the practical impact. Do not include real recipient addresses, private tokens, passwords, or application secrets.
