<?php

/**
 * Copyright 2019-2021 Wingify Software Pvt. Ltd.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace vwo;

use PHPUnit\Framework\TestCase;
use Exception as Exception;
use vwo\Utils\SegmentEvaluator;

/***
 * Class VWOTest
 *
 * @package vwo
 */
class VWOTest extends TestCase
{

    private $vwoInstance;
    private $variationResults = '';
    private $segmentEvaluatorJson = '';
    const GOAL_TYPES = ['CUSTOM', 'REVENUE', 'ALL', null];// null is for testing when goalType not set

    protected function setUp()
    {
        $this->users = TestUtil::getUsers();

        $this->settings1 = Settings1::setup();
        $this->settings2 = Settings2::setup();
        $this->settings3 = Settings3::setup();
        $this->settings4 = Settings4::setup();
        $this->settings5 = Settings5::setup();
        $this->settings6 = Settings6::setup();
        $this->settings7 = Settings7::setup();
        $this->settings8 = Settings8::setup();
        $this->settings9 = Settings9::setup();
        $segmentEvaluatorJson = new SegmentEvaluatorJson();
        $results = new VariationResults();

        $this->variationResults = $results->results;
        $this->segmentEvaluatorJson = $segmentEvaluatorJson->setting;
    }

    public function testWithWrongConfig()
    {
        $obj = new VWO([]);
        $this->assertEquals(true, is_object($obj));
    }

    public function testConfigNotArray()
    {
        $obj = new VWO(null);
        $this->assertEquals(true, is_object($obj));
    }

    function testWithCorruptedSettingsFile()
    {
        $obj = TestUtil::instantiateSdk(
            [
            'sdkKey' => 123,
            'accountId' => 1.9
            ]
        );

        $variation = $obj->activate('DEV_TEST_1', 'random-user');
        $isFeatureEnabled = $obj->isFeatureEnabled('DEV_TEST_8', 'random-user');

        $this->assertEquals(null, $variation);
        $this->assertEquals(null, $isFeatureEnabled);
    }


    public function testGetSettingsFile()
    {
        $response = VWO::getSettingsFile(1, 'randomKey');
        $this->assertEquals(false, $response);
    }

    public function testGetSettingsFileWhenTriggeredByWebhook()
    {
        $response = VWO::getSettingsFile(1, 'randomKey', true);
        $this->assertEquals(false, $response);
    }

    public function testApiException()
    {
        $obj = TestUtil::instantiateSdk($this->settings8);

        $value1 = $obj->isFeatureEnabled('FEATURE_TEST_ALL_DISABLED', $this->users[0]);
        $value2 = $obj->isFeatureEnabled('FEATURE_TEST', $this->users[0]);
        $this->assertEquals(false, $value1);
        $this->assertEquals(true, $value2);

        $obj->variationDecider = TestUtil::mockMethodToThrowEception($this, 'VariationDecider', 'fetchVariationData');

        foreach ($this->users as $userId) {
            $value1 = $obj->isFeatureEnabled('FEATURE_ROLLOUT_ONLY', $userId);
            $value2 = $obj->getFeatureVariableValue('FEATURE_ROLLOUT_ONLY', 'V1', $userId);

            $this->assertEquals(true, is_null($value1) == false && $value2 == false);
            $this->assertEquals(true, is_null($value2));
        }

        $obj = TestUtil::instantiateSdk($this->settings1);
        $obj->variationDecider = TestUtil::mockMethodToThrowEception($this, 'VariationDecider', 'fetchVariationData');

        foreach ($this->users as $userId) {
            $value1 = $obj->activate('DEV_TEST_1', $userId);
            $value2 = $obj->getVariationName('DEV_TEST_1', $userId);
            $value3 = $obj->track('DEV_TEST_1', $userId, 'CUSTOM');

            $this->assertEquals(true, is_null($value1));
            $this->assertEquals(true, is_null($value2));
            $this->assertEquals(true, is_null($value3));
        }

        $obj = TestUtil::instantiateSdk($this->settings1);
        $obj->eventDispatcher = TestUtil::mockMethodToThrowEception($this, 'EventDispatcher', 'send');
        $obj->eventDispatcher = TestUtil::mockMethodToThrowEception($this, 'EventDispatcher', 'sendAsyncRequest');

        foreach ($this->users as $userId) {
            $value = $obj->push('tagKey', 'tagValue', $userId);
            $this->assertEquals(true, is_null($value) == false && $value == false);
        }

        $value = $obj->isFeatureEnabled('DEV_TEST_1', $userId);
        $this->assertEquals(true, is_null($value));
    }

    public function testDevModeEnabled()
    {

        $obj = TestUtil::instantiateSdk($this->settings8, ['isDevelopmentMode' => 1]);
        $obj->eventDispatcher = TestUtil::mockEventDispatcher($this);

        $userId = $this->users[rand(0, count($this->users) - 1)];

        $obj->activate('FEATURE_TEST', $userId);
        $obj->isFeatureEnabled('FEATURE_TEST', $userId);
        $obj->isFeatureEnabled(1234, 2342);
        $variationName = $obj->getVariationName('FEATURE_TEST', $userId);
    }

    public function testActivate()
    {
        for ($devtest = 1; $devtest < 7; $devtest++) {
            $this->vwoInstance = TestUtil::instantiateSdk($this->{'settings' . $devtest});
            $this->vwoInstance->eventDispatcher = TestUtil::mockEventDispatcher($this);
            $campaignKey = 'DEV_TEST_' . $devtest;
            foreach ($this->users as $userId) {
                $variationName = $this->vwoInstance->activate($campaignKey, $userId);
                $expected = ucfirst($this->variationResults[$campaignKey][$userId]);
                $this->assertEquals($expected, $variationName);
            }
        }
    }

    public function testForUser()//test for shouldTrackReturningUser flag(passing in config array) on 'isFeatureEnabled','getFeatureVariableValue','getVariationName','activate' APIs
    {
        $shouldTrackReturningUserArray = [true, false, null];
        $campaignKeys = ['DEV_TEST_8','FEATURE_ROLLOUT_ONLY','FEATURE_TEST'];
        $expectedForIsFeatureEnabled = [[null, null, null], [1, 1, 1], [1, 1, 1]];
        $expectedForGetFeatureVariableValue = [null, 10, 10];
        $expectedForGetVariationName = ['Control', null, 'Control'];
        $expectedForActivate = ['Control', null, null];
        foreach ($shouldTrackReturningUserArray as $item => $shouldTrackReturningUser) {
            foreach ($campaignKeys as $index => $campaignKey) {
                $this->vwoInstance = TestUtil::instantiateSdk(
                    $this->settings8,
                    [
                        'isDevelopmentMode' => 1,
                        'isUserStorage' => 1,
                        'shouldTrackReturningUser' => $shouldTrackReturningUser
                    ]
                );
                $userId = $this->users[1];
                if($campaignKey == 'FEATURE_ROLLOUT_ONLY') {
                    $variationInfo = [
                        'userId' => $userId,
                        'variationName' => 'website',
                        'campaignKey' => $campaignKey
                    ];
                    $this->vwoInstance->_userStorageObj = TestUtil::mockUserStorageInterface($this, $variationInfo);
                }
                $this->vwoInstance->eventDispatcher = TestUtil::mockEventDispatcher($this);
                $value1 = $this->vwoInstance->isFeatureEnabled($campaignKey, $userId);
                $value2 = $this->vwoInstance->getFeatureVariableValue($campaignKey, 'V1', $userId);
                $variationName = $this->vwoInstance->getVariationName($campaignKey, $userId);
                $this->assertEquals($expectedForIsFeatureEnabled[$index][$item], $value1);
                $this->assertEquals($expectedForGetFeatureVariableValue[$index], $value2);
                $this->assertEquals($expectedForGetVariationName[$index], $variationName);
                $variationName = $this->vwoInstance->activate($campaignKey, $userId);
                $this->assertEquals($expectedForActivate[$index], $variationName);
            }
        }
    }

    public function testForUser1()//test for shouldTrackReturningUser flag(passing in options array) on 'isFeatureEnabled','getFeatureVariableValue','getVariationName','activate' APIs
    {
        $shouldTrackReturningUserArray = [true, false, null];
        $campaignKeys = ['DEV_TEST_8','FEATURE_ROLLOUT_ONLY','FEATURE_TEST'];
        $expectedForIsFeatureEnabled = [[null, null, null], [1, 1, 1], [1, 1, 1]];
        $expectedForGetFeatureVariableValue = [null, 10, 10];
        $expectedForGetVariationName = ['Control', null, 'Control'];
        $expectedForActivate = ['Control', null, null];
        foreach ($shouldTrackReturningUserArray as $item => $shouldTrackReturningUser) {
            foreach ($campaignKeys as $index => $campaignKey) {
                $this->vwoInstance = TestUtil::instantiateSdk(
                    $this->settings8,
                    ['isDevelopmentMode' => 1, 'isUserStorage' => 1]
                );

                $userId = $this->users[1];
                if($campaignKey == 'FEATURE_ROLLOUT_ONLY') {
                    $variationInfo = [
                        'userId' => $userId,
                        'variationName' => 'website',
                        'campaignKey' => $campaignKey
                    ];
                    $this->vwoInstance->_userStorageObj = TestUtil::mockUserStorageInterface($this, $variationInfo);
                }

                $this->vwoInstance->eventDispatcher = TestUtil::mockEventDispatcher($this);
                $options = ['shouldTrackReturningUser' => $shouldTrackReturningUser];
                $value1 = $this->vwoInstance->isFeatureEnabled($campaignKey, $userId, $options);
                $value2 = $this->vwoInstance->getFeatureVariableValue($campaignKey, 'V1', $userId, $options);
                $variationName = $this->vwoInstance->getVariationName($campaignKey, $userId, $options);
                $this->assertEquals($expectedForIsFeatureEnabled[$index][$item], $value1);
                $this->assertEquals($expectedForGetFeatureVariableValue[$index], $value2);
                $this->assertEquals($expectedForGetVariationName[$index], $variationName);
                $variationName = $this->vwoInstance->activate($campaignKey, $userId, $options);
                $this->assertEquals($expectedForActivate[$index], $variationName);
            }
        }
    }

    public function testGetVariationName()
    {
        for ($devtest = 1; $devtest < 7; $devtest++) {
            $this->vwoInstance = TestUtil::instantiateSdk($this->{'settings' . $devtest});
            $this->vwoInstance->eventDispatcher = TestUtil::mockEventDispatcher($this);
            $campaignKey = 'DEV_TEST_' . $devtest;
            foreach ($this->users as $userId) {
                $variationName = $this->vwoInstance->getVariationName($campaignKey, $userId);
                $expected = ucfirst($this->variationResults[$campaignKey][$userId]);
                $this->assertEquals($expected, $variationName);
            }
        }
    }

    // goal already tracked
    public function testTrackGoalIdentifierMatched()
    {
        $goalIdentifier = 'CUSTOM';
        $userStorageInstance = new UserStorageTest();
        $userStorageInstance->setGoalIdentifier($goalIdentifier);
        $config = [
            'settingsFile' => $this->settings1,
            'logging' => new CustomLogger(),
            'userStorageService' =>  $userStorageInstance
        ];
        $sdkInstance = new VWO($config);
        $result = $sdkInstance->track("DEV_TEST_1", $this->users[0], $goalIdentifier, []);
        $this->assertEquals(null, $result);
    }

    public function testShouldTrackReturningUserNotBoolean()
    {
        $this->vwoInstance = TestUtil::instantiateSdk($this->settings1, ['isUserStorage' => 1]);
        $result = $this->vwoInstance->track("DEV_TEST_1", $this->users[0], 'CUSTOM', ['shouldTrackReturningUser' => 3]);
        $this->assertEquals(true, $result);
    }

    public function testGoalTypeToTrackInvalid()
    {
        $this->vwoInstance = TestUtil::instantiateSdk($this->settings1, ['isUserStorage' => 1]);
        $result = $this->vwoInstance->track("DEV_TEST_1", $this->users[0], 'CUSTOM', ['goalTypeToTrack' => "invalid"]);
        $this->assertEquals(null, $result);
    }

    public function testTrack()
    {
        for ($devtest = 1; $devtest < 7; $devtest++) {
            $settingsFile = $this->{'settings' . $devtest};

            $this->vwoInstance = TestUtil::instantiateSdk($settingsFile, ['isDevelopmentMode' => 1]);
            $this->vwoInstance->eventDispatcher = TestUtil::mockEventDispatcher($this);
            $campaignKey = 'DEV_TEST_' . $devtest;
            $options = [];
            for ($i = 0; $i < count($this->users); $i++) {
                $userId = $this->users[$i];
                foreach ($settingsFile['campaigns'] as $campaign) {
                    if ($campaign['key'] == $campaignKey) {
                        $goalname = $campaign['goals'][0]['identifier'];
                        if ($campaign['goals'][0]['type'] == 'REVENUE_TRACKING') {
                            $options['revenueValue'] = 10;
                        }
                        break;
                    }
                }
                $this->vwoInstance->eventDispatcher = TestUtil::mockEventDispatcher($this);
                $result = $this->vwoInstance->track($campaignKey, $userId, $goalname, $options);

                $expected = ucfirst($this->variationResults[$campaignKey][$userId]);
                if ($expected == null) {
                    $expected = false;
                } else {
                    $expected = true;
                }
                $this->assertEquals($expected, $result);
            }
        }
    }

    public function testTrackGoalTypeToTrack()// passing 'goalTypeToTrack' in options
    {
        $campaignKeys = ['DEV_TEST_1', 'DEV_TEST_3', 'DEV_TEST_4'];
        $settingsFile = $this->settings9;
        foreach (self::GOAL_TYPES as $goalType) {
            $this->vwoInstance = TestUtil::instantiateSdk($settingsFile, ['isDevelopmentMode' => 1]);
            $this->vwoInstance->eventDispatcher = TestUtil::mockEventDispatcher($this);
            $options = ['goalTypeToTrack' => $goalType];
            if ($goalType == 'REVENUE') {
                $options['revenueValue'] = 10;
            }
            $goalName = 'CUSTOM';
            foreach ($this->users as $userId) {
                $this->vwoInstance->eventDispatcher = TestUtil::mockEventDispatcher($this);
                $result = $this->vwoInstance->track($campaignKeys, $userId, $goalName, $options);
                if ($goalType == 'REVENUE') {
                    $this->assertEquals(null, $result);
                } else {
                    foreach ($result as $campaignKey => $value) {
                        $expected = $this->variationResults[$campaignKey][$userId];
                        if ($expected == null) {
                            $expected = false;
                        } else {
                            $expected = true;
                        }
                        $this->assertEquals($expected, $value);
                    }
                }
            }
        }
    }

    public function testTrackGoalTypeToTrackAndShouldTrackReturningUser()// passed 'shouldTrackReturningUser' is true in config
    {
        $campaignKeys = ['DEV_TEST_1', 'DEV_TEST_3', 'DEV_TEST_4'];
        $settingsFile = $this->settings9;
        foreach (self::GOAL_TYPES as $goalType) {
            $this->vwoInstance = TestUtil::instantiateSdk($settingsFile, ['isDevelopmentMode' => 1, 'goalTypeToTrack' => $goalType, 'shouldTrackReturningUser' => true]);
            $this->vwoInstance->eventDispatcher = TestUtil::mockEventDispatcher($this);
            $options = [];
            if ($goalType == 'REVENUE') {
                $options['revenueValue'] = 10;
            }
            $goalName = 'CUSTOM';
            foreach ($this->users as $userId) {
                $this->vwoInstance->eventDispatcher = TestUtil::mockEventDispatcher($this);
                $result = $this->vwoInstance->track($campaignKeys, $userId, $goalName, $options);

                if ($goalType == 'REVENUE') {
                    $this->assertEquals(null, $result);
                } else {
                    foreach ($result as $campaignKey => $value) {
                        $expected = $this->variationResults[$campaignKey][$userId];
                        if ($expected == null) {
                            $expected = false;
                        } else {
                            $expected = true;
                        }
                        $this->assertEquals($expected, $value);
                    }
                }
            }
        }
    }

    public function testTrackCampaignKeyNull()// campaignKey is null and goalTypeToTrack and shouldTrackReturningUser(false) in Config
    {
        $goalType = 'CUSTOM';
        $campaignKeys = null;
        $settingsFile = $this->settings9;
        $this->vwoInstance = TestUtil::instantiateSdk($settingsFile, ['isDevelopmentMode' => 1, 'shouldTrackReturningUser' => false, 'goalTypeToTrack' => $goalType]);
        $this->vwoInstance->eventDispatcher = TestUtil::mockEventDispatcher($this);
        $options = [];
        $goalname = 'CUSTOM';
        for ($i = 0; $i < count($this->users); $i++) {
            $userId = $this->users[$i];
            $this->vwoInstance->eventDispatcher = TestUtil::mockEventDispatcher($this);
            $result = $this->vwoInstance->track($campaignKeys, $userId, $goalname, $options);

            foreach ($result as $campaignKey => $value) {
                $expected = $this->variationResults[$campaignKey][$userId];
                if ($expected == null) {
                    $expected = false;
                } else {
                    $expected = true;
                }
                $this->assertEquals($expected, $value);
            }
        }
    }

    public function testTrackForFeatureRollout()
    {
        $this->vwoInstance = TestUtil::instantiateSdk($this->settings8);
        $this->vwoInstance->eventDispatcher = TestUtil::mockEventDispatcher($this);

        $campaignKey = 'FEATURE_ROLLOUT_ONLY';

        foreach ($this->users as $userId) {
            $response = $this->vwoInstance->track($campaignKey, $userId, 'CUSTOM');

            $this->assertEquals(true, is_null($response));
        }
    }

    public function testTrackForRevenueGoal()
    {
        $this->vwoInstance = TestUtil::instantiateSdk($this->settings2, ['isDevelopmentMode' => 1]);
        $this->vwoInstance->eventDispatcher = TestUtil::mockEventDispatcher($this);

        $campaignKey = $this->settings2['campaigns'][0]['key'];

        foreach ($this->users as $userId) {
            $goalNotPresentResponse = $this->vwoInstance->track($campaignKey, $userId, 'UNDEFINED_GOAL_IDENTIFIER');
            $revenueGoalButNoValue = $this->vwoInstance->track($campaignKey, $userId, 'REVENUE');
            $revenueGoalWithValueResponse = $this->vwoInstance->track($campaignKey, $userId, 'REVENUE', [ 'revenueValue' => 10]);

            $this->assertEquals(true, is_null($goalNotPresentResponse));
            $this->assertEquals(false, $revenueGoalButNoValue);
            $this->assertEquals(true, is_null($revenueGoalWithValueResponse) == false && $revenueGoalWithValueResponse == true);
        }
    }

    public function testTrackForUser()
    {
        $shouldTrackReturningUserArray = [true, false, null];
        foreach ($shouldTrackReturningUserArray as $shouldTrackReturningUser) {
            $this->vwoInstance = TestUtil::instantiateSdk(
                $this->settings8,
                ['isUserStorage' => 1, 'shouldTrackReturningUser' => $shouldTrackReturningUser]
            );
            $this->vwoInstance->eventDispatcher = TestUtil::mockEventDispatcher($this);
            $campaignKey = $this->settings8['campaigns'][2]['key'];
            $userId = $this->users[0];
            $goalName = 'dsa';
            $result = $this->vwoInstance->track($campaignKey, $userId, $goalName);
            $expected = $this->variationResults[$campaignKey][$userId];
            if ($expected == null) {
                $expected = false;
            } else {
                $expected = true;
            }
            $this->assertEquals($expected, $result);
        }
    }

    public function testIsFeatureEnabled()
    {

        for ($devtest = 8; $devtest < 9; $devtest++) {
            $this->vwoInstance = TestUtil::instantiateSdk($this->{'settings' . $devtest});
            $this->vwoInstance->eventDispatcher = TestUtil::mockEventDispatcher($this);

            $featureTestKey = 'FEATURE_TEST';
            $featureTestVaritaionsDisabledKey = 'FEATURE_TEST_ALL_DISABLED';
            $featureTestZeroTrafficKey = 'FEATURE_TEST_ZERO_TRAFFIC';
            $featureRolloutZeroTrafficKey = 'FEATURE_ROLLOUT_ZERO_TRAFFIC';

            foreach ($this->users as $userId) {
                $isFeatureEnabled = $this->vwoInstance->isFeatureEnabled($featureTestKey, $userId);
                $isFeatureEnabledForZero = $this->vwoInstance->isFeatureEnabled($featureTestZeroTrafficKey, $userId);
                $isAllfeaturesDisbled = $this->vwoInstance->isFeatureEnabled($featureTestVaritaionsDisabledKey, $userId);
                $isFeatureRolloutForZeroTraffic = $this->vwoInstance->isFeatureEnabled($featureRolloutZeroTrafficKey, $userId);

                // false in case of feature rollout when traffic is zero
                // as in php false == null gives true then we have $isFeatureRolloutForZeroTraffic= false every time in this case . so handlng this here
                $this->assertEquals(true, is_null($isFeatureRolloutForZeroTraffic) == false && $isFeatureRolloutForZeroTraffic == false);

                // null everytime as traffic is zero
                $this->assertEquals(true, is_null($isFeatureEnabledForZero));

                // false when all isFeatureEnabled is false
                $this->assertEquals(true, is_null($isAllfeaturesDisbled) == false && $isAllfeaturesDisbled == false);
            }

            $isFeatureEnabledForWrongCampaign = $this->vwoInstance->isFeatureEnabled('campaign_not_running_key', $this->users[0]);
            $this->assertEquals(true, is_null($isFeatureEnabledForWrongCampaign));
        }

        $abCampaignKey = $this->settings1['campaigns'][0]['key'];

        $this->vwoInstance = TestUtil::instantiateSdk($this->settings1);
        $this->vwoInstance->eventDispatcher = TestUtil::mockEventDispatcher($this);

        $isEnabled = $this->vwoInstance->isFeatureEnabled($abCampaignKey, $this->users[0]);
        $this->assertEquals(true, is_null($isEnabled));

        $variableValue = $this->vwoInstance->getFeatureVariableValue($abCampaignKey, 'variable-key', $this->users[0]);
        $this->assertEquals(true, is_null($isEnabled));
    }

    function testGetFeatureVariableValueForFeatureRollout()
    {
        $campaignKey = 'FEATURE_ROLLOUT_ONLY';

        $this->vwoInstance = TestUtil::instantiateSdk($this->settings8);
        $this->vwoInstance->eventDispatcher = TestUtil::mockEventDispatcher($this);

        foreach ($this->users as $userId) {
            $variableV1 = $this->vwoInstance->getFeatureVariableValue($campaignKey, 'V1', $userId);

            $this->assertEquals(10, $variableV1);
        }
    }

    function testGetFeatureVariableValueForFeatureCampaign()
    {
        $featureTestKey = $this->settings8['campaigns'][1]['key'];

        $this->vwoInstance = TestUtil::instantiateSdk($this->settings8);
        $this->vwoInstance->eventDispatcher = TestUtil::mockEventDispatcher($this);

        foreach ($this->users as $userId) {
            $variation = $this->vwoInstance->getVariationName($featureTestKey, $userId);

            switch ($variation) {
            case 'Control':
                $expectedIsFeatureEnabled = true;
                $expectedFeatureVariableValue = 10;
                break;
            case 'Variation-1':
                $expectedIsFeatureEnabled = false;
                $expectedFeatureVariableValue = 10;
                break;
            case 'Variation-2':
                $expectedIsFeatureEnabled = true;
                $expectedFeatureVariableValue = 20;
                break;
            }

            $campaignKeyWrong = $this->vwoInstance->getFeatureVariableValue(123, 'V!', $userId);
            $userIdWrong = $this->vwoInstance->getFeatureVariableValue($featureTestKey, 'V!', 123);

            $featureVariableValue = $this->vwoInstance->getFeatureVariableValue($featureTestKey, 'V1', $userId);
            $featureVariableNotFoundValue = $this->vwoInstance->getFeatureVariableValue($featureTestKey, 'no-such-variable', $userId);

            $this->assertEquals(true, is_null($campaignKeyWrong));
            $this->assertEquals(true, is_null($userIdWrong));
            $this->assertEquals($expectedFeatureVariableValue, $featureVariableValue);
            $this->assertEquals(true, is_null($featureVariableNotFoundValue));
        }
    }

    public function testPushApi()
    {
        $userId = $this->users[rand(0, count($this->users) - 1)];

        $cases = [
            //empty case
            ['tagKey' => '', 'tagValue' => '', 'expected' => false],
            //length check
            ['tagKey' => 'qwertyuioppqwertyuioppqwertyuioppqwertyuioppqwertyuioppqwertyuioppqwertyuioppqwertyuioppqwertyuioppqwertyuioppqwertyuioppqwertyuioppqwertyuioppqwertyuioppqwertyuioppqwertyuioppqwertyuioppqwertyuioppqwertyuioppqwertyuioppqwertyuioppqwertyuioppqwertyuioppqwertyuioppqwertyuiopptyeytry',
                'tagValue' => 'qwertyuioppqwertyuioppqwertyuioppqwertyuioppqwertyuioppqwertyuioppqwertyuioppqwertyuioppqwertyuioppqwertyuioppqwertyuioppqwertyuioppqwertyuioppqwertyuioppqwertyuioppqwertyuioppqwertyuioppqwertyuioppqwertyuioppqwertyuioppqwertyuioppqwertyuioppqwertyuioppqwertyuioppqwertyuiopptyeytry',
                'expected' => false],
            //datatype case
            ['tagKey' => 1, 'tagValue' => 2, 'expected' => false],
            //happy case
            ['tagKey' => 'foo', 'tagValue' => 'bar', 'expected' => true],
        ];

        $this->vwoInstance = TestUtil::instantiateSdk($this->settings8, ['isUserStorage' => 1]);
        $this->vwoInstance->eventDispatcher = TestUtil::mockEventDispatcher($this);

        foreach ($cases as $case) {
            $response = $this->vwoInstance->push($case['tagKey'], $case['tagValue'], $userId);
            $this->assertEquals($case['expected'], $response);
        }

        $this->vwoInstance = TestUtil::instantiateSdk($this->settings8, ['isUserStorage' => 1, 'isDevelopmentMode' => 1]);
        $this->vwoInstance->eventDispatcher = TestUtil::mockEventDispatcher($this, 400);

        foreach ($cases as $case) {
            $response = $this->vwoInstance->push($case['tagKey'], $case['tagValue'], $userId);
            $this->assertEquals($case['expected'], $response);
        }
    }

    public function testSegmentEvaluator()
    {
        $whitlistingEvaluatorJson = new SegmentEvaluatorJson();
        $segmentData = json_decode(str_replace('\\', '\\\\', $whitlistingEvaluatorJson->setting), 1);

        foreach ($segmentData as $key => $segments) {
            foreach ($segments as $segment) {
                $segmentObj = new SegmentEvaluator();
                $res = $segmentObj->evaluate($segment['dsl'], $segment['customVariables']);
                $this->assertEquals($segment['expectation'], $res);
            }
        }
        $segmentObj = new SegmentEvaluator();
        $resForEmptyDSL = $segmentObj->evaluate([], []);
        $this->assertEquals(true, $resForEmptyDSL);
    }

    public function testWhitelisting()
    {
        $data = ['camapaignKey' => 'DEV_TEST_6', 'userId' => 'user_1'];
        $whitelistingTags = [
            'chrome' => false,
            'safari' => true,
            'browser' => 'chrome 107.107'
        ];
        $falseWhiteListingTags = [
            'chrome' => true,
            'safari' => false,
            'browser' => 'firefox 106.69'
        ];

        $whitelistingObj = new WhitelistingJson();

        $whitelistingSetting = $whitelistingObj->setting;
        $customVariables = ['contains_vwo' => 'qqvwoqq', 'regex_for_all_letters' => 'abc', 'regex_for_small_letters' => 'www', 'regex_for_zeros' => 0, 'regex_for_capital_letters' => 'ABC', 'regex_for_no_zeros' => 123, 'regex_real_number' => 123, 'starts_with' => 'vwo'];

        $this->vwoInstance = TestUtil::instantiateSdk($whitelistingSetting);
        $this->vwoInstance->eventDispatcher = TestUtil::mockEventDispatcher($this);
        $variationName = $this->vwoInstance->getVariationName($data['camapaignKey'], $data['userId'], ['variationTargetingVariables' => $whitelistingTags, 'customVariables' => $customVariables]);
        $variationNameForFalse = $this->vwoInstance->getVariationName($data['camapaignKey'], $data['userId'], ['variationTargetingVariables' => $falseWhiteListingTags, 'customVariables' => $customVariables]);

        $expected1 = 'Variation-2';
        $expected2 = 'Control';
        $this->assertEquals($expected1, $variationName);
        $this->assertEquals($expected2, $variationNameForFalse);

        $this->vwoInstance = TestUtil::instantiateSdk(
            $whitelistingSetting,
            [
                'integrations' => [
                    'callback' => function ($properties) {
                    }
                ]
            ]
        );

        $variationName = $this->vwoInstance->getVariationName($data['camapaignKey'], $data['userId'], ['variationTargetingVariables' => $whitelistingTags, 'customVariables' => $customVariables]);
        $this->assertEquals($expected1, $variationName);
    }

    public function testAdditionalDataDuringVWOInstantiation()
    {
        $config = [
            'settingsFile' => $this->settings1,
            'logging' => new CustomLogger(),
            'userStorageService' => new UserStorageTest(),
            'shouldTrackReturningUser' => true,
            'goalTypeToTrack' => 'CUSTOM',
            'integrations' => [
                'callback' => function ($properties) {
                }
            ]
        ];
        $obj = new VWO($config);
        $additionalData = $obj->usageStats->getUsageStats();
        $this->assertEquals(1, $additionalData['is_i']);
        $this->assertEquals(1, $additionalData['is_cl']);
        $this->assertEquals(1, $additionalData['is_ss']);
        $this->assertEquals(1, $additionalData['tru']);
        $this->assertEquals(1, $additionalData['gt']);
    }

    public function testAdditionalDataForLoggingAndIntegrations()
    {
        $config = [
            'settingsFile' => $this->settings1,
            'logging' => new CustomLogger(),
            'integrations' => [
                'callback' => function ($properties) {
                }
            ]
        ];
        $obj = new VWO($config);
        $additionalData = $obj->usageStats->getUsageStats();
        $this->assertEquals(1, $additionalData['is_i']);
        $this->assertEquals(1, $additionalData['is_cl']);
        $this->assertEquals(0, isset($additionalData['is_ss']));
        $this->assertEquals(0, isset($additionalData['tru']));
        $this->assertEquals(0, isset($additionalData['gt']));
    }

    public function testAdditionalDataForUserStorageAndGoalTypeToTrack()
    {
        $config = [
            'settingsFile' => $this->settings1,
            'userStorageService' => new UserStorageTest(),
            'goalTypeToTrack' => 'CUSTOM'
        ];
        $obj = new VWO($config);
        $additionalData = $obj->usageStats->getUsageStats();
        $this->assertEquals(0, isset($additionalData['is_i']));
        $this->assertEquals(0, isset($additionalData['is_cl']));
        $this->assertEquals(1, $additionalData['is_ss']);
        $this->assertEquals(0, isset($additionalData['tru']));
        $this->assertEquals(1, $additionalData['gt']);
    }

    public function testAdditionalDataForLoggingAndShouldTrackReturningUser()
    {
        $config = [
            'settingsFile' => $this->settings1,
            'logging' => new CustomLogger(),
            'shouldTrackReturningUser' => true
        ];
        $obj = new VWO($config);
        $additionalData = $obj->usageStats->getUsageStats();
        $this->assertEquals(0, isset($additionalData['is_i']));
        $this->assertEquals(1, $additionalData['is_cl']);
        $this->assertEquals(0, isset($additionalData['is_ss']));
        $this->assertEquals(1, $additionalData['tru']);
        $this->assertEquals(0, isset($additionalData['gt']));
    }

    public function testGetFeatureVariableValueFailPriorCampaignActivationForFeatureRollout()
    {
        $this->vwoInstance = TestUtil::instantiateSdk(
            $this->settings8,
            [
                'isDevelopmentMode' => 1,
                'isUserStorage' => 1
            ]
        );
        $userId = $this->users[1];
        $campaignKey = 'FEATURE_ROLLOUT_ONLY';
        $value = $this->vwoInstance->getFeatureVariableValue($campaignKey, 'V1', $userId);
        $this->assertEquals(null, $value);
    }

    public function testGetFeatureVariableValuePassAfterCampaignActivationForFeatureRollout()
    {
        $this->vwoInstance = TestUtil::instantiateSdk(
            $this->settings8,
            [
                'isDevelopmentMode' => 1,
                'isUserStorage' => 1
            ]
        );
        $this->vwoInstance->eventDispatcher = TestUtil::mockEventDispatcher($this);
        $userId = $this->users[1];
        $campaignKey = 'FEATURE_ROLLOUT_ONLY';

        $variationInfo = [
            'userId' => $userId,
            'variationName' => 'website',
            'campaignKey' => $campaignKey
        ];
        $this->vwoInstance->_userStorageObj = TestUtil::mockUserStorageInterface($this, $variationInfo);
        $value = $this->vwoInstance->getFeatureVariableValue($campaignKey, 'V1', $userId);
        $this->assertEquals(10, $value);
    }
}
