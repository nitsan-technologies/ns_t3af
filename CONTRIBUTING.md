# Contributing to AI Foundation for TYPO3 (T3AF)

Thanks for helping improve T3AF. Contributions of all kinds are welcome: bug reports, fixes, features, tests and documentation.

## License of your contribution (inbound = outbound)

T3AF is licensed under **GPL-2.0-or-later** (see [LICENSE](LICENSE)). Contributions follow the TYPO3 extension standard of *inbound = outbound*:

> By submitting a contribution (for example a pull request), you agree that it is licensed under GPL-2.0-or-later, the same license as this extension.

Production / commercial entitlement terms are documented separately in [COMMERCIAL-LICENSE.md](COMMERCIAL-LICENSE.md). They do not change the GPL for contributions.

There is **no Contributor License Agreement (CLA)**. The TYPO3 Association's CLA applies to the TYPO3 Core, not to extensions, and we do not require one. You keep ownership of your work; you simply license it to the project under the same terms as the rest of the code.

## Sign your commits (DCO)

We use the **Developer Certificate of Origin (DCO)**. It is a lightweight, one-line confirmation that you have the right to submit your contribution. It is not a CLA and transfers no rights.

Add a `Signed-off-by` line to each commit by committing with `-s`:

```
git commit -s -m "Fix: correct provider fallback order"
```

This appends, using your real name and email:

```
Signed-off-by: Your Name <you@example.com>
```

By signing off you agree to the DCO (https://developercertificate.org/).

## How to contribute

1. **Open an issue first** for anything non-trivial, so we can agree on the approach before you invest time.
2. **Branch** from the default branch. Use a short, descriptive branch name.
3. **Follow the coding standards.** Run the project's checks before pushing:
   ```
   composer cs:fix      # coding standards (php-cs-fixer)
   composer phpstan     # static analysis
   composer test        # unit and functional tests
   ```
   (See `composer.json` scripts. CI runs the same checks on PHP 8.2 to 8.4 and TYPO3 v12 to v14.)
4. **Add or update tests** for any behaviour you change.
5. **Keep the GPL file header** on every source file (php-cs-fixer inserts and enforces it).
6. **Open a pull request** with a clear description of what changed and why. Link the related issue.

## Reporting bugs

Use the issue templates. Include your TYPO3 version, PHP version, T3AF version, and clear steps to reproduce. For **security issues, do not open a public issue**: follow `SECURITY.md`.

## Trademarks

The code is GPL and yours to fork. "T3AF" and "AI Universe" are trademarks of T3Planet / NITSAN Technologies; the GPL covers the code, not the marks. Please rename any public fork so it is not presented as the official product.

## Questions

Open a discussion or issue, or reach us at support@t3planet.de.
