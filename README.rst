rector-single-commit
====================

"git commit" each applied php-rector__ rule as a single commit.

__ https://getrector.com/


Example output
--------------
``git log --oneline`` after running this tool::

  0260f7de TKT-23: Reconfigure ext_emconf.php files
  86d8a5f7 TKT-23: Add "#[\Override]" attribute to overridden methods
  152c9e53 TKT-23: Decorate read-only properties with "readonly" attribute
  3b2a47d3 TKT-23: Change "null" to empty string for strictly defined function arguments
  639210c4 TKT-23: Change switch() to match()
  16f4f02b TKT-23: Replace strpos() !== false and strstr() with str_contains()
  33bbffad TKT-23: Convert substr() to str_starts_with()
  51ce15fe TKT-23: Convert simple property initialization to constructor promotion
  19dabac3 TKT-23: Remove unused variable in catch block
  f4e58f07 TKT-23: Move optional parameters after required ones in method signatures
  f2707863 TKT-23: Update php version in rector config
  bf663a23 TKT-23: Change typo3-rector extension constraints


Usage
-----
Run it with an optional git commit message prefix::

   rector-single-commit.sh "TICKET-23: "

It will then create a single git commit for each rector rule.


Commit messages
---------------
``rector-single-commit.sh`` expects a commit message text file for each
rule in the ``messages/`` directory.

If such a file does not exist, it will print the expected file name
and stop processing.

The `find a rule`__ tool on rector helps to get a short description
for a given rule.

__ https://getrector.com/find-rule


Syncing commit messages
-----------------------
``sync-rule-messages.sh`` keeps ``messages/`` in line with the rules of the
globally installed rector packages (``rector/rector`` incl. its bundled
symfony/phpunit/doctrine/downgrade rules, and every ``rector-extension``
package like ``ssch/typo3-rector``)::

   sync-rule-messages.sh                  # dry run, only reports
   sync-rule-messages.sh --apply          # write the changes
   sync-rule-messages.sh --apply ~/proj   # also rules only installed in ~/proj

New files contain the rule's own description, a blank line and its
``@changelog`` links (or its getrector.com page).

``messages.lock.json`` remembers the generated content of the last sync, so
reworded messages survive updates:

``NEW``
  Rule has no message file; it is created.
``UPDATED``
  Rule description changed and the file was not reworded; it is overwritten.
``REVIEW``
  Rule description changed but the file was reworded by hand; it is kept,
  check the wording yourself.
``REMOVED``
  Rule no longer exists in its installed package; the file is deleted.
``SKIPPED``
  No installed package provides the rule (e.g. rector-laravel without a
  project dir); the file is kept.
``FAILED``
  The rule's description could not be determined; nothing is written.

``verify-rule-messages.sh`` lists all messages that differ from the rule's
own description.


Fractor
-------
``fractor-single-commit.sh`` does the same for fractor__ rules
(TypoScript, FlexForm XML, Fluid, YAML, XLIFF)::

   fractor-single-commit.sh "TICKET-23: "

It needs a ``fractor.php`` in the current directory and uses the global
``fractor`` executable. Commit messages share the ``messages/`` directory.
A missing message file is created from the rule's description and its
``@changelog`` link to the TYPO3 changelog.

If fractor fails with a missing service (e.g. the TypoScript parser
``Builder``), run ``composer global install`` so
``a9f/fractor-extension-installer`` regenerates its extension list.

__ https://github.com/andreaswolf/fractor


Environment variables
---------------------

``$RECTOR_PATH``
  Use the path given in this variable instead of ``vendor/bin/rector``.


Dependencies
------------
- ``bash``
- ``rector`` 2.0 or later
- ``fractor`` (only for ``fractor-single-commit.sh``)
- ``jq``


License
-------
AGPLv3
