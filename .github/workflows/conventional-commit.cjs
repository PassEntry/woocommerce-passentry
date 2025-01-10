'use strict';
const config = require('conventional-changelog-conventionalcommits');

module.exports = config({
  issuePrefixes: ['PS-'],
  issueUrlFormat: 'https://passentry.atlassian.net/browse/{{prefix}}{{id}}',
});
