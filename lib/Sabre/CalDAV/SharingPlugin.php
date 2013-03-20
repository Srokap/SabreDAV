<?php

namespace Sabre\CalDAV;

use Sabre\DAV;

/**
 * This plugin implements support for caldav sharing.
 *
 * This spec is defined at:
 * http://svn.calendarserver.org/repository/calendarserver/CalendarServer/trunk/doc/Extensions/caldav-sharing.txt
 *
 * See:
 * Sabre\CalDAV\Backend\SharingSupport for all the documentation.
 *
 * Note: This feature is experimental, and may change in between different
 * SabreDAV versions.
 *
 * @copyright Copyright (C) 2007-2013 Rooftop Solutions. All rights reserved.
 * @author Evert Pot (http://www.rooftopsolutions.nl/)
 * @license http://code.google.com/p/sabredav/wiki/License Modified BSD License
 */
class SharingPlugin extends DAV\ServerPlugin {

    /**
     * These are the various status constants used by sharing-messages.
     */
    const STATUS_ACCEPTED = 1;
    const STATUS_DECLINED = 2;
    const STATUS_DELETED = 3;
    const STATUS_NORESPONSE = 4;
    const STATUS_INVALID = 5;

    /**
     * Reference to SabreDAV server object.
     *
     * @var Sabre\DAV\Server
     */
    protected $server;

    /**
     * This method should return a list of server-features.
     *
     * This is for example 'versioning' and is added to the DAV: header
     * in an OPTIONS response.
     *
     * @return array
     */
    public function getFeatures() {

        return array('calendarserver-sharing');

    }

    /**
     * Returns a plugin name.
     *
     * Using this name other plugins will be able to access other plugins
     * using Sabre\DAV\Server::getPlugin
     *
     * @return string
     */
    public function getPluginName() {

        return 'caldav-sharing';

    }

    /**
     * This initializes the plugin.
     *
     * This function is called by Sabre\DAV\Server, after
     * addPlugin is called.
     *
     * This method should set up the required event subscriptions.
     *
     * @param DAV\Server $server
     * @return void
     */
    public function initialize(DAV\Server $server) {

        $this->server = $server;
        $server->resourceTypeMapping['Sabre\\CalDAV\\ISharedCalendar'] = '{' . Plugin::NS_CALENDARSERVER . '}shared';

        array_push(
            $this->server->protectedProperties,
            '{' . Plugin::NS_CALENDARSERVER . '}invite',
            '{' . Plugin::NS_CALENDARSERVER . '}allowed-sharing-modes',
            '{' . Plugin::NS_CALENDARSERVER . '}shared-url'
        );

        $this->server->subscribeEvent('beforeGetProperties', array($this, 'beforeGetProperties'));
        $this->server->subscribeEvent('afterGetProperties', array($this, 'afterGetProperties'));
        $this->server->subscribeEvent('updateProperties', array($this, 'updateProperties'));
        $this->server->subscribeEvent('unknownMethod', array($this,'unknownMethod'));

        $elementMap = [

            // Requests
            '{' . Plugin::NS_CALENDARSERVER . '}share'        => 'Sabre\\CalDAV\\XML\\Request\\Share',
            '{' . Plugin::NS_CALENDARSERVER . '}invite-reply' => 'Sabre\\CalDAV\\XML\\Request\\InviteReply',

            // Other
            '{' . Plugin::NS_CALENDARSERVER . '}set'    => 'Sabre\\XML\\Element\\KeyValue',
            '{' . Plugin::NS_CALENDARSERVER . '}remove' => 'Sabre\\XML\\Element\\KeyValue',

        ];
        foreach($elementMap as $k=>$v) {
            $server->xml->elementMap[$k] = $v;
        }
    }

    /**
     * This event is triggered when properties are requested for a certain
     * node.
     *
     * This allows us to inject any properties early.
     *
     * @param string $path
     * @param DAV\INode $node
     * @param array $requestedProperties
     * @param array $returnedProperties
     * @return void
     */
    public function beforeGetProperties($path, DAV\INode $node, &$requestedProperties, &$returnedProperties) {

        if ($node instanceof IShareableCalendar) {
            if (($index = array_search('{' . Plugin::NS_CALENDARSERVER . '}invite', $requestedProperties))!==false) {

                unset($requestedProperties[$index]);
                $returnedProperties[200]['{' . Plugin::NS_CALENDARSERVER . '}invite'] =
                    new Property\Invite(
                        $node->getShares()
                    );

            }

        }

        if ($node instanceof ISharedCalendar) {

            if (($index = array_search('{' . Plugin::NS_CALENDARSERVER . '}shared-url', $requestedProperties))!==false) {

                unset($requestedProperties[$index]);
                $returnedProperties[200]['{' . Plugin::NS_CALENDARSERVER . '}shared-url'] =
                    new DAV\Property\Href(
                        $node->getSharedUrl()
                    );

            }
            // The 'invite' property is slightly different for the 'shared'
            // instance of the calendar, as it also contains the owner
            // information.
            if (($index = array_search('{' . Plugin::NS_CALENDARSERVER . '}invite', $requestedProperties))!==false) {

                unset($requestedProperties[$index]);

                // Fetching owner information
                $props = $this->server->getPropertiesForPath($node->getOwner(), array(
                    '{http://sabredav.org/ns}email-address',
                    '{DAV:}displayname',
                ), 1);

                $ownerInfo = array(
                    'href' => $node->getOwner(),
                );

                if (isset($props[0][200])) {

                    // We're mapping the internal webdav properties to the
                    // elements caldav-sharing expects.
                    if (isset($props[0][200]['{http://sabredav.org/ns}email-address'])) {
                        $ownerInfo['href'] = 'mailto:' . $props[0][200]['{http://sabredav.org/ns}email-address'];
                    }
                    if (isset($props[0][200]['{DAV:}displayname'])) {
                        $ownerInfo['commonName'] = $props[0][200]['{DAV:}displayname'];
                    }

                }

                $returnedProperties[200]['{' . Plugin::NS_CALENDARSERVER . '}invite'] =
                    new Property\Invite(
                        $node->getShares(),
                        $ownerInfo
                    );

            }


        }

    }

    /**
     * This method is triggered *after* all properties have been retrieved.
     * This allows us to inject the correct resourcetype for calendars that
     * have been shared.
     *
     * @param string $path
     * @param array $properties
     * @param DAV\INode $node
     * @return void
     */
    public function afterGetProperties($path, &$properties, DAV\INode $node) {

        if ($node instanceof IShareableCalendar) {
            if (isset($properties[200]['{DAV:}resourcetype'])) {
                if (count($node->getShares())>0) {
                    $properties[200]['{DAV:}resourcetype']->add(
                        '{' . Plugin::NS_CALENDARSERVER . '}shared-owner'
                    );
                }
            }
            $propName = '{' . Plugin::NS_CALENDARSERVER . '}allowed-sharing-modes';
            if (array_key_exists($propName, $properties[404])) {
                unset($properties[404][$propName]);
                $properties[200][$propName] = new Property\AllowedSharingModes(true,false);
            }

        }

    }

    /**
     * This method is trigged when a user attempts to update a node's
     * properties.
     *
     * A previous draft of the sharing spec stated that it was possible to use
     * PROPPATCH to remove 'shared-owner' from the resourcetype, thus unsharing
     * the calendar.
     *
     * Even though this is no longer in the current spec, we keep this around
     * because OS X 10.7 may still make use of this feature.
     *
     * @param array $mutations
     * @param array $result
     * @param DAV\INode $node
     * @return void
     */
    public function updateProperties(array &$mutations, array &$result, DAV\INode $node) {

        if (!$node instanceof IShareableCalendar)
            return;

        if (!isset($mutations['{DAV:}resourcetype'])) {
            return;
        }

        // Only doing something if shared-owner is indeed not in the list.
        if($mutations['{DAV:}resourcetype']->is('{' . Plugin::NS_CALENDARSERVER . '}shared-owner')) return;

        $shares = $node->getShares();
        $remove = array();
        foreach($shares as $share) {
            $remove[] = $share['href'];
        }
        $node->updateShares(array(), $remove);

        // We're marking this update as 200 OK
        $result[200]['{DAV:}resourcetype'] = null;

        // Removing it from the mutations list
        unset($mutations['{DAV:}resourcetype']);

    }

    /**
     * This event is triggered when the server didn't know how to handle a
     * certain request.
     *
     * We intercept this to handle POST requests on calendars.
     *
     * @param string $method
     * @param string $uri
     * @return null|bool
     */
    public function unknownMethod($method, $uri) {

        if ($method!=='POST') {
            return;
        }

        // Only handling xml
        $contentType = $this->server->httpRequest->getHeader('Content-Type');
        if (strpos($contentType,'application/xml')===false && strpos($contentType,'text/xml')===false)
            return;

        // Making sure the node exists
        try {
            $node = $this->server->tree->getNodeForPath($uri);
        } catch (DAV\Exception\NotFound $e) {
            return;
        }

        $requestBody = $this->server->httpRequest->getBody(true);

        // If this request handler could not deal with this POST request, it
        // will return 'null' and other plugins get a chance to handle the
        // request.
        //
        // However, we already requested the full body. This is a problem,
        // because a body can only be read once. This is why we preemptively
        // re-populated the request body with the existing data.
        $this->server->httpRequest->setBody($requestBody);

        $request = $this->server->xml->parse($requestBody, $rootNode);

        switch($rootNode) {

            // Dealing with the 'share' document, which modified invitees on a
            // calendar.
            case '{' . Plugin::NS_CALENDARSERVER . '}share' :

                // We can only deal with IShareableCalendar objects
                if (!$node instanceof IShareableCalendar) {
                    return;
                }

                $this->server->transactionType = 'post-calendar-share';

                // Getting ACL info
                $acl = $this->server->getPlugin('acl');

                // If there's no ACL support, we allow everything
                if ($acl) {
                    $acl->checkPrivileges($uri, '{DAV:}write');
                }

                $node->updateShares($request->set, $request->remove);

                $this->server->httpResponse->sendStatus(200);
                // Adding this because sending a response body may cause issues,
                // and I wanted some type of indicator the response was handled.
                $this->server->httpResponse->setHeader('X-Sabre-Status', 'everything-went-well');

                // Breaking the event chain
                return false;

            // The invite-reply document is sent when the user replies to an
            // invitation of a calendar share.
            case '{'. Plugin::NS_CALENDARSERVER.'}invite-reply' :

                // This only works on the calendar-home-root node.
                if (!$node instanceof UserCalendars) {
                    return;
                }
                $this->server->transactionType = 'post-invite-reply';

                // Getting ACL info
                $acl = $this->server->getPlugin('acl');

                // If there's no ACL support, we allow everything
                if ($acl) {
                    $acl->checkPrivileges($uri, '{DAV:}write');
                }

                $url = $node->shareReply(
                    $request->href,
                    $request->status,
                    $request->calendarUri,
                    $request->inReplyTo,
                    $request->summary
                );

                $this->server->httpResponse->sendStatus(200);
                // Adding this because sending a response body may cause issues,
                // and I wanted some type of indicator the response was handled.
                $this->server->httpResponse->setHeader('X-Sabre-Status', 'everything-went-well');

                if ($url) {
                    $dom = new \DOMDocument('1.0', 'UTF-8');
                    $dom->formatOutput = true;

                    $root = $dom->createElement('cs:shared-as');
                    foreach($this->server->xmlNamespaces as $namespace => $prefix) {
                        $root->setAttribute('xmlns:' . $prefix, $namespace);
                    }

                    $dom->appendChild($root);
                    $href = new DAV\Property\Href($url);

                    $href->serialize($this->server, $root);
                    $this->server->httpResponse->setHeader('Content-Type','application/xml');
                    $this->server->httpResponse->sendBody($dom->saveXML());

                }

                // Breaking the event chain
                return false;

            case '{' . Plugin::NS_CALENDARSERVER . '}publish-calendar' :

                // We can only deal with IShareableCalendar objects
                if (!$node instanceof IShareableCalendar) {
                    return;
                }
                $this->server->transactionType = 'post-publish-calendar';

                // Getting ACL info
                $acl = $this->server->getPlugin('acl');

                // If there's no ACL support, we allow everything
                if ($acl) {
                    $acl->checkPrivileges($uri, '{DAV:}write');
                }

                $node->setPublishStatus(true);

                // iCloud sends back the 202, so we will too.
                $this->server->httpResponse->sendStatus(202);

                // Adding this because sending a response body may cause issues,
                // and I wanted some type of indicator the response was handled.
                $this->server->httpResponse->setHeader('X-Sabre-Status', 'everything-went-well');

                // Breaking the event chain
                return false;

            case '{' . Plugin::NS_CALENDARSERVER . '}unpublish-calendar' :

                // We can only deal with IShareableCalendar objects
                if (!$node instanceof IShareableCalendar) {
                    return;
                }
                $this->server->transactionType = 'post-unpublish-calendar';

                // Getting ACL info
                $acl = $this->server->getPlugin('acl');

                // If there's no ACL support, we allow everything
                if ($acl) {
                    $acl->checkPrivileges($uri, '{DAV:}write');
                }

                $node->setPublishStatus(false);

                $this->server->httpResponse->sendStatus(200);

                // Adding this because sending a response body may cause issues,
                // and I wanted some type of indicator the response was handled.
                $this->server->httpResponse->setHeader('X-Sabre-Status', 'everything-went-well');

                // Breaking the event chain
                return false;

        }

    }

}
