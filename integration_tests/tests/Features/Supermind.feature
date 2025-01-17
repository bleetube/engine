@supermind
Feature: Supermind

  Scenario: Successful Supermind request creation with Stripe payment
    Given I want to create an activity with the following details
      """json
      {
        "message": "This is a test post for supermind request from integration tests",
        "wire_threshold": null,
        "paywall": null,
        "time_created": null,
        "mature": false,
        "nsfw": null,
        "tags": [
            "test_tag"
        ],
        "access_id": "2",
        "license": "all-rights-reserved",
        "post_to_permaweb": false,
        "entity_guid_update": true,
        "supermind_request": {
            "receiver_guid": "",
            "payment_options": {
                "payment_type": 0,
                "payment_method_id": "",
                "amount": 10.53
            },
            "reply_type": 0,
            "twitter_required": false,
            "terms_agreed": true
        }
      }
      """
    And I login to "create" Supermind requests
    When I "PUT" stored data "activity_details" to the "v3/newsfeed/activity" endpoint
    Then I get a 200 response containing
      """json
      {
        "type": "activity"
      }
      """

  Scenario: Successful Supermind request creation with offchain token payment
    Given I want to create an activity with the following details
      """json
      {
        "message": "This is a test post for supermind request from integration tests",
        "wire_threshold": null,
        "paywall": null,
        "time_created": null,
        "mature": false,
        "nsfw": null,
        "tags": [
            "test_tag"
        ],
        "access_id": "2",
        "license": "all-rights-reserved",
        "post_to_permaweb": false,
        "entity_guid_update": true,
        "supermind_request": {
            "receiver_guid": "",
            "payment_options": {
                "payment_type": 1,
                "amount": 10.00
            },
            "reply_type": 0,
            "twitter_required": false,
            "terms_agreed": true
        }
      }
      """
    And I login to "create" Supermind requests
    When I "PUT" stored data "activity_details" to the "v3/newsfeed/activity" endpoint
    Then I get a 200 response containing
      """json
      {
        "type": "activity"
      }
      """

  Scenario: Supermind request creation with invalid details
    Given I want to create an activity with the following details
      """json
      {
        "message": "This is a test post for supermind request from integration tests",
        "wire_threshold": [],
        "paywall": null,
        "time_created": 213123121,
        "mature": true,
        "nsfw": null,
        "tags": [
            "test_tag"
        ],
        "access_id": "1",
        "license": "all-rights-reserved",
        "post_to_permaweb": false,
        "entity_guid_update": true,
        "supermind_request": {
            "receiver_guid": "",
            "payment_options": {
                "payment_type": 1,
                "amount": 0.50
            },
            "reply_type": -1,
            "terms_agreed": false
        }
      }
      """
    And I login to "create" Supermind requests
    When I "PUT" stored data "activity_details" to the "v3/newsfeed/activity" endpoint
    Then I get a 400 response containing
      """json
      {}
      """

  Scenario: Accept Supermind request successfully
    Given I create a Supermind request with the following details
      """json
      {
        "message": "This is a test post for supermind request from integration tests",
        "wire_threshold": null,
        "paywall": null,
        "time_created": null,
        "mature": false,
        "nsfw": null,
        "tags": [
            "test_tag"
        ],
        "access_id": "2",
        "license": "all-rights-reserved",
        "post_to_permaweb": false,
        "entity_guid_update": true,
        "supermind_request": {
            "receiver_guid": "",
            "payment_options": {
                "payment_type": 1,
                "amount": 10.00
            },
            "reply_type": 0,
            "twitter_required": false,
            "terms_agreed": true
        }
      }
      """
    And I login to "receive" Supermind requests
    When I accept the Supermind request for stored data "created_activity" with the following reply
      """json
      {
        "message": "Lorem ipsum dolor sit amet, consectetuer adipiscing elit. Aenean commodo ligula eget dolor. Aenean massa. Cum sociis natoque penatibus et magnis dis parturient montes, nascetur ridiculus mus. Donec quam felis, ultricies nec, pellentesque eu, pretium quis, ",
        "wire_threshold": null,
        "paywall": null,
        "time_created": null,
        "mature": false,
        "nsfw": [],
        "tags": [
            "test_tag"
        ],
        "access_id": "2",
        "license": "all-rights-reserved",
        "remind_guid": "",
        "post_to_permaweb": false,
        "entity_guid_update": true,
        "supermind_reply_guid": ""
      }
      """
    Then I get a 200 response containing
      """json
      {}
      """

  Scenario: Accept Supermind request failed with validation errors
    Given I create a Supermind request with the following details
      """json
      {
        "message": "This is a test post for supermind request from integration tests",
        "wire_threshold": null,
        "paywall": null,
        "time_created": null,
        "mature": false,
        "nsfw": null,
        "tags": [
            "test_tag"
        ],
        "access_id": "2",
        "license": "all-rights-reserved",
        "post_to_permaweb": false,
        "entity_guid_update": true,
        "supermind_request": {
            "receiver_guid": "",
            "payment_options": {
                "payment_type": 1,
                "amount": 10.00
            },
            "reply_type": 0,
            "twitter_required": false,
            "terms_agreed": true
        }
      }
      """
    And I login to "receive" Supermind requests
    When I accept the Supermind request for stored data "created_activity" with the following reply
      """json
      {
        "message": "This is a test post for supermind request reply from integration tests",
        "wire_threshold": [],
        "paywall": null,
        "time_created": 1663081913,
        "mature": true,
        "nsfw": [],
        "tags": [
            "test_tag"
        ],
        "access_id": "1",
        "license": "all-rights-reserved",
        "remind_guid": "",
        "post_to_permaweb": false,
        "entity_guid_update": true,
        "supermind_reply_guid": ""
      }
      """
    Then I get a 400 response containing
      """json
      {}
      """

  Scenario: Reject Supermind request successfully
    Given I create a Supermind request with the following details
      """json
      {
        "message": "This is a test post for supermind request from integration tests",
        "wire_threshold": null,
        "paywall": null,
        "time_created": null,
        "mature": false,
        "nsfw": null,
        "tags": [
            "test_tag"
        ],
        "access_id": "2",
        "license": "all-rights-reserved",
        "post_to_permaweb": false,
        "entity_guid_update": true,
        "supermind_request": {
            "receiver_guid": "",
            "payment_options": {
                "payment_type": 1,
                "amount": 10.00
            },
            "reply_type": 0,
            "twitter_required": false,
            "terms_agreed": true
        }
      }
      """
    And I login to "receive" Supermind requests
    When I reject the Supermind request for stored data "created_activity"
    Then I get a 200 response containing
      """json
      {}
      """

  Scenario: Get Supermind inbox
    Given I login to "receive" Supermind requests
    When I call the "v3/supermind/inbox" endpoint with params
      """json
      [
          {
            "key": "limit",
            "value": 12
          },
          {
            "key": "offset",
            "value": 0
          }
        ]
      """
    Then I get a 200 response containing
      """json
      {}
      """

  Scenario: Get Supermind inbox with valid status parameter
    Given I login to "receive" Supermind requests
    When I call the "v3/supermind/inbox" endpoint with params
      """json
      [
          {
            "key": "limit",
            "value": 12
          },
          {
            "key": "offset",
            "value": 0
          },
          {
            "key": "status",
            "value": 1
          }
        ]
      """
    Then I get a 200 response containing
      """json
      {}
      """

  Scenario: Get Supermind inbox with invalid status parameter
    Given I login to "receive" Supermind requests
    When I call the "v3/supermind/inbox" endpoint with params
      """json
      [
          {
            "key": "limit",
            "value": 12
          },
          {
            "key": "offset",
            "value": 0
          },
          {
            "key": "status",
            "value": 0
          }
        ]
      """
    Then I get a 400 response containing
      """json
      {}
      """

  Scenario: Get Supermind outbox
    Given I login to "create" Supermind requests
    When I call the "v3/supermind/outbox" endpoint with params
      """json
      [
          {
            "key": "limit",
            "value": 12
          },
          {
            "key": "offset",
            "value": 0
          }
        ]
      """
    Then I get a 200 response containing
    """json
    {}
    """
  Scenario: Accepting Supermind request from unauthorized account
    Given I create a Supermind request with the following details
      """json
      {
        "message": "This is a test post for supermind request from integration tests",
        "wire_threshold": null,
        "paywall": null,
        "time_created": null,
        "mature": false,
        "nsfw": null,
        "tags": [
            "test_tag"
        ],
        "access_id": "2",
        "license": "all-rights-reserved",
        "post_to_permaweb": false,
        "entity_guid_update": true,
        "supermind_request": {
            "receiver_guid": "",
            "payment_options": {
                "payment_type": 1,
                "amount": 10.00
            },
            "reply_type": 0,
            "twitter_required": false,
            "terms_agreed": true
        }
      }
      """
    When I accept the Supermind request for stored data "created_activity" with the following reply
      """json
      {
        "message": "This is a test post for supermind request from integration tests",
        "wire_threshold": null,
        "paywall": null,
        "time_created": null,
        "mature": false,
        "nsfw": [],
        "tags": [
            "test_tag"
        ],
        "access_id": "2",
        "license": "all-rights-reserved",
        "remind_guid": "",
        "post_to_permaweb": false,
        "entity_guid_update": true,
        "supermind_reply_guid": ""
      }
      """
    Then I get a 403 response containing
      """json
      {}
      """

  Scenario: Rejecting Supermind request from unauthorized account
    Given I create a Supermind request with the following details
      """json
      {
        "message": "This is a test post for supermind request from integration tests",
        "wire_threshold": null,
        "paywall": null,
        "time_created": null,
        "mature": false,
        "nsfw": null,
        "tags": [
            "test_tag"
        ],
        "access_id": "2",
        "license": "all-rights-reserved",
        "post_to_permaweb": false,
        "entity_guid_update": true,
        "supermind_request": {
            "receiver_guid": "",
            "payment_options": {
                "payment_type": 1,
                "amount": 10.00
            },
            "reply_type": 0,
            "twitter_required": false,
            "terms_agreed": true
        }
      }
      """
    When I reject the Supermind request for stored data "created_activity"
    Then I get a 403 response containing
      """json
      {}
      """

  Scenario: Get Supermind outbox with valid status parameter
    Given I login to "create" Supermind requests
    When I call the "v3/supermind/outbox" endpoint with params
      """json
      [
          {
            "key": "limit",
            "value": 12
          },
          {
            "key": "offset",
            "value": 0
          },
          {
            "key": "status",
            "value": 1
          }
        ]
      """
    Then I get a 200 response containing
      """json
      {}
      """

  Scenario: Get Supermind outbox with invalid status parameter
    Given I login to "create" Supermind requests
    When I call the "v3/supermind/outbox" endpoint with params
      """json
      [
          {
            "key": "limit",
            "value": 12
          },
          {
            "key": "offset",
            "value": 0
          },
          {
            "key": "status",
            "value": 0
          }
        ]
      """
    Then I get a 400 response containing
      """json
      {}
      """

  Scenario: Get a single Supermind
    Given I create a Supermind request with the following details
      """json
      {
        "message": "This is a test post for supermind request from integration tests",
        "wire_threshold": null,
        "paywall": null,
        "time_created": null,
        "mature": false,
        "nsfw": null,
        "tags": [
            "test_tag"
        ],
        "access_id": "2",
        "license": "all-rights-reserved",
        "post_to_permaweb": false,
        "entity_guid_update": true,
        "supermind_request": {
            "receiver_guid": "",
            "payment_options": {
                "payment_type": 1,
                "amount": 10.00
            },
            "reply_type": 0,
            "twitter_required": false,
            "terms_agreed": true
        }
      }
      """
    When I call the single Supermind endpoint with last created Supermind guid
    Then I get a 200 response containing
    """json
      {}
    """

  Scenario: Unable to get single Supermind with a guid that has no attached entity
    Given I login to "create" Supermind requests
    When I call the "v3/supermind/123" endpoint with params
    """json
      {}
    """
    Then I get a 404 response containing
    """json
      {}
    """

  Scenario: Get default supermind settings
    Given I register to Minds with
      """json
      {
        "username": "",
        "password": "Pa$$w0rd",
        "email": "noreply@minds.com",
        "captcha": "{\"clientText\": \"captcha_bypass\"}",
        "parentId": ""
      }
      """
    When I call the "v3/supermind/settings" endpoint with params
      """json
        {}
      """
    Then I get a 200 response containing
      """json
        {
          "min_offchain_tokens": 1,
          "min_cash": 1
        }
      """

  Scenario: Change supermind settings
    Given I register to Minds with
      """json
      {
        "username": "",
        "password": "Pa$$w0rd",
        "email": "noreply@minds.com",
        "captcha": "{\"clientText\": \"captcha_bypass\"}",
        "parentId": ""
      }
      """
    And I have "supermind_settings" data
      """json
        {
          "min_offchain_tokens": 3.14,
          "min_cash": 20
        }
      """
    When I "POST" stored data "supermind_settings" to the "v3/supermind/settings" endpoint
    Then I get a 200 response containing
      """json
        {}
      """

  Scenario: Change single supermind setting
    Given I register to Minds with
      """json
      {
        "username": "",
        "password": "Pa$$w0rd",
        "email": "noreply@minds.com",
        "captcha": "{\"clientText\": \"captcha_bypass\"}",
        "parentId": ""
      }
      """
    And I have "supermind_settings" data
      """json
        { "min_cash": 20 }
      """
    When I "POST" stored data "supermind_settings" to the "v3/supermind/settings" endpoint
    Then I get a 200 response containing
      """json
        {}
      """

  Scenario: Try to change supermind settings to have too many decimal places
    Given I register to Minds with
      """json
      {
        "username": "",
        "password": "Pa$$w0rd",
        "email": "noreply@minds.com",
        "captcha": "{\"clientText\": \"captcha_bypass\"}",
        "parentId": ""
      }
      """
    And I have "supermind_settings" data
      """json
        {
          "min_offchain_tokens": 2.001,
          "min_cash": 20.001
        }
      """
    When I "POST" stored data "supermind_settings" to the "v3/supermind/settings" endpoint
    Then I get a 400 response containing
      """json
      {}
      """

  Scenario: Try to change supermind settings to have too low a value
    Given I register to Minds with
      """json
      {
        "username": "",
        "password": "Pa$$w0rd",
        "email": "noreply@minds.com",
        "captcha": "{\"clientText\": \"captcha_bypass\"}",
        "parentId": ""
      }
      """
    And I have "supermind_settings" data
      """json
        {
          "min_offchain_tokens": 0.9,
          "min_cash": 9
        }
      """
    When I "POST" stored data "supermind_settings" to the "v3/supermind/settings" endpoint
    Then I get a 400 response containing
      """json
      {}
      """

  Scenario: Try to change settings when not logged in
    Given I have "supermind_settings" data
      """json
        {
          "min_offchain_tokens": 0.9,
          "min_cash": 9
        }
      """
    When I "POST" stored data "supermind_settings" to the "v3/supermind/settings" endpoint
    Then I get a 401 response containing
      """json
      {}
      """

  Scenario: Count inbox requests with no status param
    Given I login to "receive" Supermind requests
    When I call the "v3/supermind/inbox/count" endpoint with params
      """json
        {}
      """
    Then I get a 200 response containing
      """json
        {}
      """

  Scenario: Count inbox requests with status param
    Given I login to "receive" Supermind requests
    When I call the "v3/supermind/inbox/count" endpoint with params
       """json
        [
          {
            "key": "status",
            "value": 1
          }
        ]
      """
    Then I get a 200 response containing
      """json
        {}
      """

  Scenario: Count inbox requests with invalid status param
    Given I login to "receive" Supermind requests
    When I call the "v3/supermind/inbox/count" endpoint with params
       """json
        [
          {
            "key": "status",
            "value": 0
          }
        ]
      """
    Then I get a 400 response containing
      """json
        {}
      """

  Scenario: Count outbox requests with no status param
    Given I login to "send" Supermind requests
    When I call the "v3/supermind/outbox/count" endpoint with params
      """json
        {}
      """
    Then I get a 200 response containing
      """json
        {}
      """

  Scenario: Count outbox requests with status param
    Given I login to "send" Supermind requests
    When I call the "v3/supermind/outbox/count" endpoint with params
       """json
        [
          {
            "key": "status",
            "value": 1
          }
        ]
      """
    Then I get a 200 response containing
      """json
        {}
      """

  Scenario: Count outbox requests with invalid status param
    Given I login to "send" Supermind requests
    When I call the "v3/supermind/outbox/count" endpoint with params
       """json
        [
          {
            "key": "status",
            "value": 0
          }
        ]
      """
    Then I get a 400 response containing
      """json
        {}
      """
