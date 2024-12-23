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


Environment variables
---------------------

``$RECTOR_PATH``
  Use the path given in this variable instead of ``vendor/bin/rector``.


Dependencies
------------
- ``bash``
- ``rector`` 2.0 or later
- ``jq``


License
-------
AGPLv3
