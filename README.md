# Passentry WooCommerce Plugin

This plugin allows you to create passes using Passentry API.

## Features

- Create a digital wallet pass for each order
- Add static field values to the pass
- Add dynamic fields to the pass (beta)
- Enable NFC and QR code for the pass

## How to use

- Go to the Passentry dashboard and create a pass template
- Add this download the zip and install it in your wordpress site
- activate the plugin
- go to the settings page and add your Passentry API key
- Select "Enable Pass Delivery" for your chosen product
- Enter the Passentry Template UUID in the "Passentry Template UUID" field
- The required fields to create a pass will the be added to the product as custom fields
- Add the static or dynamic values to the fields and save the product
- Create an order and the pass will be created and a button will be preset for the customer to download their pass.

## Installation
1. Download the latest release.
2. Upload it to your WordPress site under **Plugins > Add New**.
3. Activate the plugin.

## Contributing
1. Fork the repository.
2. Make your changes.
3. Submit a pull request.

# Commit Guidelines
We use [Conventional Commits](https://www.conventionalcommits.org/) for clear and automated versioning. Each commit message should be structured as follows:

### Types
- `feat`: New feature
- `fix`: Bug fix
- `docs`: Documentation changes
- `style`: Code style changes (formatting, missing semi-colons, etc)
- `refactor`: Code changes that neither fix bugs nor add features
- `test`: Adding or modifying tests
- `chore`: Changes to build process or auxiliary tools

# Branch Naming Guidelines
Branch names should follow the pattern: `{type}/{issue-number}-{description}`

### Types
Use the same types as commit guidelines:
- `feat`
- `fix`
- `docs`
- `style`
- `refactor`
- `test`
- `chore`

### Examples
- `feat/123-add-pass-validation`
- `fix/456-settings-crash`
- `docs/789-update-readme`
- `style/321-prettier-formatting`

### Breaking Changes
Breaking changes can be indicated in two ways:
1. Using `!` after the type/scope: `type(scope)!: description`
2. In the footer section of the commit, starting with "BREAKING CHANGE: " followed by a description

### Examples
- `feat(api): add pass template validation [#123]`
- `fix(admin): resolve settings page crash [#456]`
- `docs(readme): update installation instructions [#789]`
- `style(lint): apply prettier formatting [#321]`
- `feat(api)!: change pass creation endpoint [#555]`
- `feat(api): add new validation rules [#777]`
  BREAKING CHANGE: Pass creation now requires authentication token



