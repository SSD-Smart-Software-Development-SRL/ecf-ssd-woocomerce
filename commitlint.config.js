module.exports = {
  extends: ['@commitlint/config-conventional'],
  rules: {
    'type-enum': [
      2,
      'always',
      [
        'feat',     // New feature          → minor version bump
        'fix',      // Bug fix              → patch version bump
        'perf',     // Performance           → patch version bump
        'refactor', // Code improvement      → patch version bump
        'docs',     // Documentation only    → no version bump
        'style',    // Formatting only       → no version bump
        'test',     // Tests only            → no version bump
        'ci',       // CI/CD changes         → no version bump
        'chore',    // Maintenance           → no version bump
        'revert',   // Revert commit         → patch version bump
      ],
    ],
    'subject-case': [0],
  },
};
