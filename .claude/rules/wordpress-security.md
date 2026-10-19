---
paths:
  - "**/*.php"
---

# WordPress security

- Check capabilities before privileged admin, AJAX or REST operations. A nonce is never authorization on its own.
- Verify nonces on every state-changing request. REST routes need a real `permission_callback`; never `__return_true` on routes that write.
- Unslash, then validate and sanitize untrusted input with the API that fits its type. Escape at output with the context-specific function.
- Use `$wpdb->prepare()` for any SQL with variables.
- Outbound HTTP through `wp_remote_*` with a timeout; treat user-supplied URLs as SSRF risks.
- Never hardcode keys or tokens; never log credentials, tokens, personal data or raw request payloads.
- File uploads and zip extraction: validate type and contents, never extract into a web-reachable path without checks, clean up on every exit path.
- For security-sensitive changes, consider unauthenticated, unauthorized, malformed and boundary inputs, and involve `security-reviewer`.
