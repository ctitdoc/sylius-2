# Scenario [[configure-alma]], steps 1 to 3 and the refusals; Terms [[merchant-configuration-form]],
# [[merchant-configuration]], [[payment-plan]], [[one-alma-configuration-per-shop]]. Alma is the mock
# server of tests/MockAlma, reached through ALMA_API_URL.
@alma @configure_alma @ui
Feature: Configuring Alma
    In order to let Buyers pay in instalments
    As a Merchant
    I want to make Alma available in the Shop with my API key and the plans Alma allows

    Background:
        Given the store operates on a single channel in "EUR" currency
        And I am logged in as an administrator

    Scenario: Saving a verified test key reads the plans and activates the three-instalment plan by default
        When I want to create a new Alma payment method
        And I name it "Alma" in "English (United States)"
        And I specify its code as "alma"
        And I enter the test API key "sk_test_behat_0001"
        And I add it
        Then I should be notified that it has been successfully created
        And the Alma configuration should carry the merchant identifier "merchant_demo_0001"
        And the plans Alma allows should be stored, with "general_3_0_0" active by default

    Scenario: An invalid key is refused with the reason, nothing is created
        When I want to create a new Alma payment method
        And I name it "Alma" in "English (United States)"
        And I specify its code as "alma"
        And I enter the test API key "bad"
        And I try to add it
        Then I should be notified that the API key is invalid or unreachable
        And no Alma payment method should exist

    Scenario: A second Alma payment method is refused
        Given the store has the Alma payment method configured with the test key "sk_test_behat_0001"
        When I want to create a new Alma payment method
        And I name it "Alma again" in "English (United States)"
        And I specify its code as "alma_again"
        And I enter the test API key "sk_test_behat_0002"
        And I try to add it
        Then I should be notified that an Alma payment method already exists
