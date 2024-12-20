rector-single-commit
====================

"git commit" each applied php-rector__ rule as a single commit.

__ https://getrector.com/


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


Dependencies
------------
- ``bash``
- ``rector`` 2.0 or later
- ``jq``


License
-------
AGPLv3
