@local @local_instantcoursecompletion
Feature: Configure the instant course completion plugin
  In order to control which courses have their completion accelerated
  As an administrator
  I need to reach the plugin settings and the report

  Background:
    Given I log in as "admin"

  Scenario: The settings page offers the documented scope modes
    When I navigate to "Plugins > Local plugins > Instant course completion" in site administration
    Then I should see "Observer scope"
    And the "Observer scope" select box should contain "All courses on this site"
    And the "Observer scope" select box should contain "Selected category branches"
    And I should see "Plan time-based criteria in advance"
    And I should see "Scheduling horizon"
    And I should see "Enable safety-net reconcile task"
    And I should see "Enable logging"

  Scenario: The adele scope is hidden while local_adele is absent
    When I navigate to "Plugins > Local plugins > Instant course completion" in site administration
    Then the "Observer scope" select box should not contain "Use local_adele settings (category branches / tags)"

  Scenario: Saving a category scope keeps the selection
    Given the following "categories" exist:
      | name       | category | idnumber |
      | Compliance | 0        | CMPL     |
    When I navigate to "Plugins > Local plugins > Instant course completion" in site administration
    And I set the field "Observer scope" to "Selected category branches"
    And I set the field "Category branches" to "Compliance"
    And I press "Save changes"
    Then I should see "Changes saved"
    And the field "Observer scope" matches value "Selected category branches"
    And the field "Category branches" matches value "Compliance"

  Scenario: The report warns while logging is disabled
    When I navigate to "Reports > Accelerated completions" in site administration
    Then I should see "Accelerated completions"
    And I should see "Logging is currently disabled"

  Scenario: The report reports an empty result once logging is enabled
    Given the following config values are set as admin:
      | enablelogging | 1 | local_instantcoursecompletion |
    When I navigate to "Reports > Accelerated completions" in site administration
    Then I should see "Accelerated completions"
    And I should not see "Logging is currently disabled"
    And I should see "No accelerated completions have been recorded yet"

  @javascript
  Scenario: Category and tag fields only apply to the categories scope
    When I navigate to "Plugins > Local plugins > Instant course completion" in site administration
    And I set the field "Observer scope" to "All courses on this site"
    Then I should not see "Included course tags"
    When I set the field "Observer scope" to "Selected category branches"
    Then I should see "Category branches"
    And I should see "Included course tags"
    And I should see "Excluded course tags"

  @javascript
  Scenario: Horizon and budget only apply while scheduling is enabled
    When I navigate to "Plugins > Local plugins > Instant course completion" in site administration
    And I set the field "Plan time-based criteria in advance" to "0"
    Then I should not see "Scheduling horizon"
    And I should not see "Maximum enrolments examined per run"
    When I set the field "Plan time-based criteria in advance" to "1"
    Then I should see "Scheduling horizon"
    And I should see "Maximum enrolments examined per run"
