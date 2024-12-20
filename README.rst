rector-single-commit
====================

"git commit" each applied php-rector__ rule as a single commit.

__ https://getrector.com/


Usage
-----
Run it with an optional git commit message prefix::

   rector-single-commit.sh "TICKET-23: "

It will then create a single git commit for each rector rule.


Dependencies
------------
- ``bash``
- ``rector`` 2.0 or later
- ``jq``


License
-------
AGPLv3
