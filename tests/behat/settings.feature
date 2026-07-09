@local @local_instantcoursecompletion
Feature: Instant course completion settings page
  As an administrator
  I want to configure the observer scope and processing mode
  So that instant course completion acts only on the intended courses

  Scenario: Administrator can open the plugin settings page
    Given I log in as "admin"
    When I navigate to "Plugins > Local plugins > Instant course completion" in site administration
    Then I should see "Observer scope"
    And I should see "Processing mode"

  Scenario: The scope defaults to all courses
    Given I log in as "admin"
    When I navigate to "Plugins > Local plugins > Instant course completion" in site administration
    Then the field "Observer scope" matches value "All courses on this site"
