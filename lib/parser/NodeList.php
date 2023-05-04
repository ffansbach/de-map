<?php
namespace ffmap\parser;

use ffmap;

class NodeList extends Base
{
    /**
     * @var array
     */
    protected array $nodelistCommunities = [];

    /**
     * @return array
     */
    public function getNodelistCommunities(): array
    {
        return $this->nodelistCommunities;
    }

    /**
     * @param array $currentParseObject
     * @param string $comUrl
     * @return bool
     */
    public function getFrom(array $currentParseObject, string $comUrl): bool
    {
        $this->currentParseObject = $currentParseObject;
        $comName = $this->currentParseObject['name'];
        $result = $this->curlHelper->doCall($comUrl);

        $responseObject = json_decode($result);

        if (!$responseObject) {
            $this->addCommunityMessage('│└ ' . $comUrl . ' returns no valid json');
            return false;
        }

        $schemaString = file_get_contents(__DIR__ . '/../../schema/nodelist-schema-1.0.0.json');
        $schema = json_decode($schemaString);
        $validationResult = \Jsv4::validate($responseObject, $schema);

        if (!$validationResult) {
            $this->addCommunityMessage('│├ ' . $comUrl . ' is no valid nodelist');
            $this->addCommunityMessage('│└ check https://github.com/ffansbach/nodelist for nodelist-schema');
            return false;
        }

        if (empty($responseObject->nodes)) {
            $this->addCommunityMessage('│└ ' . $comUrl . ' contains no nodes');
            return false;
        }

        $routers = $responseObject->nodes;

        // add community to the list of nodelist-communities
        // this will make us skipp further search for other formats
        $this->nodelistCommunities[] = $comName;

        $counter = 0;
        $skipped = 0;
        $duplicates = 0;
        $added = 0;
        $dead = 0;

        foreach ($routers as $router) {
            $counter++;

            $location = $this->getLocation($router);

            if (!$location) {
                // router has no location
                $skipped++;
                continue;
            }

            $thisRouter = [
                'id' => (string)$router->id,
                'lat' => (string)$location->lat,
                'long' => (string) $location->long,
                'name' => isset($router->name) ? (string)$router->name : (string)$router->id,
                'community' => $comName,
                'status' => 'unknown',
                'clients' => 0,
            ];

            if (isset($router->status)) {
                if (isset($router->status->clients)) {
                    $thisRouter['clients'] = (int)$router->status->clients;
                }

                if (isset($router->status->online)) {
                    $thisRouter['status'] = (bool)$router->status->online ? 'online' : 'offline';
                }
            }


            if ($thisRouter['status'] == 'offline') {
                if (empty($router->status->lastcontact)) {
                    $isDead = true;
                } else {
                    $date = date_create((string)$router->status->lastcontact);

                    // was online in last days? ?
                    $isDead = ((time() - $date->getTimestamp()) > 60 * 60 * 24 * $this->maxAge);
                }

                if ($isDead) {
                    $dead++;
                    continue;
                }
            }

            // add to routerlist for later use in JS
            if (call_user_func($this->perNodeCallback, $thisRouter)) {
                $added++;
            } else {
                $duplicates++;
            }
        }

        $this->addCommunityMessage('│└ parsing done. ' .
            $counter . ' nodes found, ' .
            $added . ' added, ' .
            $skipped . ' skipped, ' .
            $duplicates . ' duplicates, ' .
            $dead . ' dead');

        return true;
    }

    /**
     * @param object $router
     * @return false|object
     */
    protected function getLocation(object $router)
    {
        if (empty($router->position->lat)
            ||
            (
                empty($router->position->lon)
                &&
                empty($router->position->long)
            )
        ) {
            // router has no location
            return false;
        }

        return (object) [
            'lat' => (string)$router->position->lat,
            'long' => (empty($router->position->lon)
                ? (string)$router->position->long
                : (string)$router->position->lon)
        ];
    }

    /**
     * adds an message-entry for the current community
     * @param string $message
     */
    private function addCommunityMessage(string $message)
    {
        $this->communityDebug->addMessage($message, $this->currentParseObject);
    }
}
