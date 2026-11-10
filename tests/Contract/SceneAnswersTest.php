<?php

declare(strict_types=1);

namespace Pixonaut\Sdk\Tests\Contract;

use PHPUnit\Framework\TestCase;
use Pixonaut\Sdk\Generated\Model\PixelConversationGetResponseConversationMessagesItemUser;
use Pixonaut\Sdk\Generated\Model\PixelConversationGetResponseConversationMessagesItemUserContentOption2ItemToolResult;
use Pixonaut\Sdk\Generated\Model\PixelConversationGetResponseConversationMessagesItemUserContentOption2ItemToolResultContentOption2ItemPicture;
use Pixonaut\Sdk\Generated\Model\PixelConversationGetResponseConversationMessagesItemUserContentOption2ItemToolResultContentOption2ItemPictureOfSceneCandidate;
use Pixonaut\Sdk\Tests\Contract\Support\ContractClient;
use Pixonaut\Sdk\Tests\Fake\Spec;
use stdClass;

/**
 * Scenes as the API answers them: a workspace's own scene composed from a
 * stock one, whether its person can be replaced, and a scene candidate a
 * composition shows in the conversation. Every answer is one the spec allows
 * (the fake checks it), and the SDK reads it.
 */
final class SceneAnswersTest extends TestCase
{
    private const HTTP_OK = 200;

    /** The candidate's place among the pictures one composition offers. */
    private const CANDIDATE_SLOT = 2;

    public function testAWorkspacesOwnSceneIsReadApartFromAStockOne(): void
    {
        $client = new ContractClient();
        $answer = self::example('scenes.list');
        self::assertIsArray($answer->scenes);
        $stock = $answer->scenes[0];
        self::assertInstanceOf(stdClass::class, $stock);
        $stock->hasPerson = true;
        $stock->person = 'woman';
        $stock->personSwappable = true;
        $stock->workspace = false;
        $own = clone $stock;
        $own->id = 'tee-studio-own';
        $own->personSwappable = false;
        $own->workspace = true;
        $answer->scenes = [$stock, $own];
        $client->fake()->answer('scenes.list', self::HTTP_OK, $answer);

        $scenes = $client->pixonaut->blanks()->listScenes();

        self::assertSame([], $client->fake()->violations());
        self::assertSame([true, false], array_map(static fn(object $scene): bool => $scene->personSwappable, $scenes->scenes));
        self::assertSame([false, true], array_map(static fn(object $scene): bool => $scene->workspace, $scenes->scenes));
    }

    public function testASceneCandidatePictureInAToolResultIsRead(): void
    {
        $client = new ContractClient();
        $answer = self::example('pixelConversation.get');
        $conversation = $answer->conversation;
        self::assertInstanceOf(stdClass::class, $conversation);
        $conversation->messages = [(object) [
            'role' => 'user',
            'content' => [(object) [
                'type' => 'tool_result',
                'tool_use_id' => 'toolu_compose',
                'content' => [(object) [
                    'type' => 'picture',
                    'of' => (object) ['kind' => 'scene_candidate', 'id' => 'cmp_kitchen', 'slot' => self::CANDIDATE_SLOT],
                ]],
            ]],
        ]];
        $client->fake()->answer('pixelConversation.get', self::HTTP_OK, $answer);

        $read = $client->pixonaut->pixel()->getConversation();

        self::assertSame([], $client->fake()->violations());
        self::assertNotNull($read->conversation);
        $message = $read->conversation->messages[0];
        self::assertInstanceOf(PixelConversationGetResponseConversationMessagesItemUser::class, $message);
        self::assertIsArray($message->content);
        $result = $message->content[0];
        self::assertInstanceOf(PixelConversationGetResponseConversationMessagesItemUserContentOption2ItemToolResult::class, $result);
        self::assertIsArray($result->content);
        $picture = $result->content[0];
        self::assertInstanceOf(PixelConversationGetResponseConversationMessagesItemUserContentOption2ItemToolResultContentOption2ItemPicture::class, $picture);
        $candidate = $picture->of;
        self::assertInstanceOf(PixelConversationGetResponseConversationMessagesItemUserContentOption2ItemToolResultContentOption2ItemPictureOfSceneCandidate::class, $candidate);
        self::assertSame('cmp_kitchen', $candidate->id);
        self::assertSame(self::CANDIDATE_SLOT, $candidate->slot);
    }

    /**
     * A copy of the spec's success example for `$operation`.
     */
    private static function example(string $operation): stdClass
    {
        $example = Spec::pinned()->operation($operation)->answerExample(self::HTTP_OK);
        $copy = json_decode(json_encode($example, JSON_THROW_ON_ERROR), flags: JSON_THROW_ON_ERROR);
        self::assertInstanceOf(stdClass::class, $copy);

        return $copy;
    }
}
