<?php
namespace ffmap;

class CommunityDebug
{
    protected array $debugLogCommunities = [];

    /**
     * @param string $message
     * @param array $communityData
     */
    public function addMessage(string $message, array $communityData)
    {
        if (!isset($this->debugLogCommunities[$communityData['name']])) {
            $this->debugLogCommunities[$communityData['name']] = [
                'name' => $communityData['name'],
                'apifile' => $communityData['source'],
                'message' => []
            ];
        }

        $this->debugLogCommunities[$communityData['name']]['message'][] = $message;
    }

    /**
     * @return array[]
     */
    public function getDebugLog():array
    {
        return $this->debugLogCommunities;
    }

    /**
     * adds some basic information from the communityfile to the logging/debug-object
     *
     * @param object $community
     * @param array $communityData
     * @return void
     */
    public function addBasicLogInfo(object $community, array $communityData)
    {
        $cName = $communityData['name'];
        $this->debugLogCommunities[$cName]['claimed_nodecount'] = false;

        if (!empty($community->state) && !empty($community->state->nodes)) {
            $this->debugLogCommunities[$cName]['claimed_nodecount'] = (int)$community->state->nodes;
        }

        if (isset($community->metacommunity)) {
            $this->debugLogCommunities[$cName]['metacommunity'] = $community->metacommunity;
        }
    }
}
