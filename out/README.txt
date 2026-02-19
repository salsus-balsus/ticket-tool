Word export (New Issue)
=======================
- Place your template here as: template.docx
- Template placeholders: {IssueNum}, {ShortDesc}, {Priority}, {System}, {Client}, {Reporter}, {ObjectType}, {FixDev}
  Optional: use ${IssueNum} etc. instead of {IssueNum} for more reliable replacement when Word splits text.
- When a new ticket is created, the app fills the template and saves a copy under out/<4-digit-issue-no>/ with a VBA-style filename.
- PHPWord is required: in project root run "composer install". PHP zip extension must be enabled.
