# Scenario [[pay-in-instalments]], steps 2 and 3: the plan chosen at checkout and the hand-over to Alma;
# Terms [[eligibility-check]], [[payment-plan-choice]], [[amount-within-plan-bounds]], [[payment-creation]],
# [[payment-reference]], [[alma-payment]]. Alma is the mock server of tests/MockAlma.
@alma @pay_in_instalments @ui
Feature: Paying in instalments, up to the hand-over to Alma
    In order to pay an Order in several instalments
    As a Buyer
    I want to choose an Alma plan at checkout and be handed over to Alma to pay the first one

    Background:
        Given the store operates on a single channel in "EUR" currency
        And the store ships to "France"
        And the store has a zone "France" with code "FR"
        And it has the "France" country member
        And the store has a product "Festival Ruffle Wrap" priced at "€34.28"
        And the store ships everywhere for Free
        And the store has the Alma payment method configured with the test key "sk_test_behat_0001"

    Scenario: Choosing the three-instalment plan and being handed over to Alma
        Given I have 2 products "Festival Ruffle Wrap" in the cart
        And I am at the checkout addressing step
        When I specify the email as "buyer@example.com"
        And I specify the billing address as "Paris", "1 rue de Rivoli", "75001", "France" for "Jeanne Martin"
        And I complete the addressing step
        And I select "Free" shipping method
        And I complete the shipping step
        Then Alma should be offered with the plans "general_2_0_0", "general_3_0_0" and "general_4_0_0"
        When I select "Alma" payment method
        And I choose the Alma plan "general_3_0_0"
        And I complete the payment step
        Then I should be on the checkout complete step
        When I confirm my order with Alma
        Then I should be handed over to Alma's hosted page
        And Alma should hold a payment of 6856 cents in 3 instalments for my order
        And the Shop should keep the reference of that payment

    Scenario: Below the plans' minimum, Alma is not offered
        Given I have 1 product "Festival Ruffle Wrap" in the cart
        And I am at the checkout addressing step
        When I specify the email as "buyer@example.com"
        And I specify the billing address as "Paris", "1 rue de Rivoli", "75001", "France" for "Jeanne Martin"
        And I complete the addressing step
        And I select "Free" shipping method
        And I complete the shipping step
        Then Alma should not be offered
