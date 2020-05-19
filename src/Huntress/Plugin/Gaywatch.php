<?php

/**
 * Copyright (c) 2019 Keira Dueck <sylae@calref.net>
 * Use of this source code is governed by the MIT license, which
 * can be found in the LICENSE file.
 */

namespace Huntress\Plugin;

use Carbon\Carbon;
use CharlotteDunois\Yasmin\Models\Message;
use CharlotteDunois\Yasmin\Models\MessageEmbed;
use Doctrine\DBAL\Schema\Schema;
use Exception;
use Huntress\DatabaseFactory;
use Huntress\EventListener;
use Huntress\Huntress;
use Huntress\PluginHelperTrait;
use Huntress\PluginInterface;
use React\Promise\ExtendedPromiseInterface as Promise;
use stdClass;
use Throwable;
use function html5qp;
use function Sentry\captureException;

/**
 * Simple builtin to show user information
 *
 * @author Keira Sylae Aro <sylae@calref.net>
 */
class Gaywatch implements PluginInterface
{
    use PluginHelperTrait;

    public static function register(Huntress $bot)
    {
        $bot->eventManager->addEventListener(EventListener::new()->addEvent("dbSchema")->setCallback([
            self::class,
            'db',
        ]));
        $bot->eventManager->addURLEvent("https://forums.spacebattles.com/forums/worm.115/", 300,
            [self::class, "sbHell"]);

        $bot->on(self::PLUGINEVENT_COMMAND_PREFIX . "gaywatch", [self::class, "gaywatch"]);
    }

    public static function db(Schema $schema): void
    {
        $t = $schema->createTable("pct_sbhell");
        $t->addColumn("idTopic", "integer");
        $t->addColumn("timeTopicPost", "datetime");
        $t->addColumn("timeLastReply", "datetime");
        $t->addColumn("gaywatch", "boolean", ['default' => false]);
        $t->addColumn("title", "string",
            ['customSchemaOptions' => DatabaseFactory::CHARSET, 'notnull' => false]);
        $t->setPrimaryKey(["idTopic"]);
    }

    public static function gaywatch(Huntress $bot, Message $message): ?Promise
    {
        try {
            $t = self::_split($message->content);
            if (count($t) < 2) {
                $qb = DatabaseFactory::get()->createQueryBuilder();
                $qb->select("*")->from("pct_sbhell")->where('`gaywatch` = 1');
                $res = $qb->execute()->fetchAll();
                $r = [];
                if ($message->member->roles->has(406698099143213066)) {
                    $r[] = "As a mod, you can add a gaywatch fic using `!gaywatch SBFicID`";
                }
                foreach ($res as $data) {
                    $title = $data['title'] ?? "<Title unknown>";
                    $r[] = "*$title* - <https://forums.spacebattles.com/threads/{$data['idTopic']}/>";
                }
                return $message->channel->send(implode("\n", $r), ['split' => true]);
            }
            if (is_numeric($t[1]) && $message->member->roles->has(406698099143213066)) {

                $isRemove = (self::isGaywatch((object) ['id' => $t[1]]));
                $t[1] = (int) $t[1];
                $defaultTime = Carbon::now();
                $query = DatabaseFactory::get()->prepare('INSERT INTO pct_sbhell (`idTopic`, `timeTopicPost`, `timeLastReply`, `gaywatch`) VALUES(?, ?, ?, ?) '
                    . 'ON DUPLICATE KEY UPDATE `gaywatch`=VALUES(`gaywatch`);',
                    ['string', 'datetime', 'datetime', 'integer']);
                $query->bindValue(1, $t[1]);
                $query->bindValue(2, $defaultTime);
                $query->bindValue(3, $defaultTime);
                $query->bindValue(4, (int) !$isRemove);
                $query->execute();
                if (!$isRemove) {
                    return $message->channel->send("<a:gaybulba:504954316394725376> :eyes: I am now watching for updates to SB thread {$t[1]}.");
                } else {
                    return $message->channel->send(":pensive: I am no longer watching for updates to SB thread {$t[1]}.");
                }
            }
        } catch (Throwable $e) {
            return self::exceptionHandler($message, $e);
        }
    }

    private static function isGaywatch(stdClass $post): bool
    {
        $qb = DatabaseFactory::get()->createQueryBuilder();
        $qb->select("*")->from("pct_sbhell")->where('`idTopic` = ?')->setParameter(0, $post->id, "integer");
        $res = $qb->execute()->fetchAll();
        foreach ($res as $data) {
            return (bool) $data['gaywatch'] ?? false;
        }
        return false;
    }

    public static function sbHell(string $string, Huntress $bot)
    {
        try {
            $data = html5qp($string);
            $items = $data->find('div.structItem--thread');
            foreach ($items as $item) {
                try {
                    $x = (object) [
                        'id' => preg_replace('/.*?(\d+)\/?$/', '$1',
                            $item->find('.structItem-title > a')->attr("href")),
                        'title' => trim($item->find('.structItem-title > a')->text()),
                        'threadTime' => new Carbon($item->find('.structItem-startDate time')->attr('datetime')),
                        'replyTime' => new Carbon($item->find('time.structItem-latestDate')->attr('datetime')),
                        'author' => [
                            'name' => $item->attr('data-author'),
                            'av' => "https://forums.spacebattles.com" . $item->find('.structItem-cell--icon:not(.structItem-cell--iconEnd) img')->attr("src"),
                            'url' => "https://forums.spacebattles.com" . $item->find('.structItem-cell--icon:not(.structItem-cell--iconEnd) a')->attr("href"),
                        ],
                        'replier' => [
                            'av' => "https://forums.spacebattles.com" . $item->find('.structItem-cell--iconEnd img')->attr("src"),
                            'url' => "https://forums.spacebattles.com" . $item->find('.structItem-cell--iconEnd a')->attr("href"),
                        ],
                        'numReplies' => trim($item->find('.structItem-cell--meta dl:not(.structItem-minor) dd')->text()),
                        'numViews' => trim($item->find('.structItem-cell--meta dl.structItem-minor dd')->text()),
                        'wordcount' => trim(str_replace("Words:", "",
                            $item->find("li:nth-child(3) a")->text())),
                    ];

                    if (is_null($x->threadTime) || is_null($x->replyTime)) {
                        continue;
                    }

                    // gaywatch
                    if (self::isGaywatch($x) && self::lastPost($x) < $x->replyTime) {
                        if ($x->author['name'] == $x->replier['name']) {
                            // op update
                            $embed = new MessageEmbed();
                            $embed->setTitle($x->title)->setColor(0x00ff00)
                                ->setURL("https://forums.spacebattles.com/threads/{$x->id}/unread")
                                ->setAuthor($x->author['name'], $x->author['av'], $x->author['url'])
                                ->addField("Created", $x->threadTime->toFormattedDateString(), true)
                                ->addField("Replies", $x->numReplies, true)
                                ->addField("Views", $x->numViews, true)
                                ->setFooter("Last reply")
                                ->setTimestamp($x->replyTime->timestamp);

                            if (mb_strlen($x->wordcount) > 0) {
                                $embed->addField("Wordcount", $x->wordcount, true);
                            }
                            $bot->channels->get(540449157320802314)->send("<@&5 DONT PING THEM THIS IS A TEST 40465395576864789>: {$x->author['name']} has updated *{$x->title}*\n<https://forums.spacebattles.com/threads/{$x->id}/unread>",
                                ['embed' => $embed]);
                        } else {
                            // not op update
                            $bot->channels->get(540449157320802314)->send("SB member {$x->replier['name']} has replied to *{$x->title}*\n<https://forums.spacebattles.com/threads/{$x->id}/unread>");
                        }
                    }


                    // push to db
                    $query = DatabaseFactory::get()->prepare('INSERT INTO pct_sbhell (`idTopic`, `timeTopicPost`, `timeLastReply`, `title`) VALUES(?, ?, ?, ?) '
                        . 'ON DUPLICATE KEY UPDATE `timeLastReply`=VALUES(`timeLastReply`), `timeTopicPost`=VALUES(`timeTopicPost`), `title`=VALUES(`title`);',
                        ['string', 'datetime', 'datetime', 'string']);
                    $query->bindValue(1, $x->id);
                    $query->bindValue(2, $x->threadTime);
                    $query->bindValue(3, $x->replyTime);
                    $query->bindValue(4, $x->title);
                    $query->execute();
                } catch (Throwable $e) {
                    captureException($e);
                    $bot->log->warning($e->getMessage(), ['exception' => $e]);
                }
            }
        } catch (Throwable $e) {
            captureException($e);
            $bot->log->warning($e->getMessage(), ['exception' => $e]);
        }
    }

    private static function lastPost(stdClass $post): Carbon
    {
        $qb = DatabaseFactory::get()->createQueryBuilder();
        $qb->select("*")->from("pct_sbhell")->where('`idTopic` = ?')->setParameter(0, $post->id, "integer");
        $res = $qb->execute()->fetchAll();
        foreach ($res as $data) {
            return new Carbon($data['timeLastReply']);
        }
        throw new Exception("No results found for that post");
    }

    private static function alreadyPosted(stdClass $post): bool
    {
        $qb = DatabaseFactory::get()->createQueryBuilder();
        $qb->select("*")->from("pct_sbhell")->where('`idTopic` = ?')->setParameter(0, $post->id, "integer");
        $res = $qb->execute()->fetchAll();
        foreach ($res as $data) {
            return true;
        }
        return false;
    }
}
