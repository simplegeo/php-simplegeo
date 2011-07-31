<?php
namespace SimpleGeo;

include(dirname(__FILE__) . '/OAuth.php');
include(dirname(__FILE__) . '/CURL.php');

use SimpleGeo\OAuthConsumer;
use SimpleGeo\OAuthRequest;
use SimpleGeo\OAuthSignatureMethod_HMAC_SHA1;
use SimpleGeo\CURL;

/**
	Contains a latitude and longitude. Simply represents a coordinate
**/
class GeoPoint {
	public $lat;
	public $lng;
	
	public function __construct($lat, $lng) {
		$this->lat = $lat;
		$this->lng = $lng;
	}
}

/**
	A ContextResult object represents the results of the API call to the /1.0/context methods.
	This object can be used to retrieve data in a more structured format than parsing through
	the array on its own.
	
	@author Aaron Parecki <aaron@parecki.com>
**/
class ContextResult {
	private $data;

	public function __construct(array $data) {
		$this->data = $data;
	}
	
	/**
	 * Returns a single feature of the requested category type, or FALSE if none was found.
	 * If more than one feature was found matching the category, the best one is returned.
	 */
	public function getFeatureOfCategory($category) {
		if(!$this->_featuresExists())
			return FALSE;
		
		$candidates = $this->getFeaturesOfCategory($category);

		if(count($candidates) == 0)
			return FALSE;

		if(count($candidates) == 1)
			return $candidates[0];
		
		// Sometimes there are more than one result for a given category, in these
		// cases, we want to return the one that has an "abbr" because it's usually better.
		foreach($candidates as $c) {
			if($c->data['abbr'])
				return $c;
		}
		
		// If none had 'abbr', then just return the first
		return $candidates[0];
	}
	
	/**
	 * Returns an array of all features matching the requested category
	 */
	public function getFeaturesOfCategory($category) {
		if(!$this->_featuresExists())
			return array();
		
		$features = array();
		foreach($this->data['features'] as $f) {
			if(array_key_exists('classifiers', $f) && is_array($f['classifiers'])) {
				foreach($f['classifiers'] as $c) {
					if($c['category'] == $category) {
						$features[] = new ContextFeature($f);
					}
				}
			}
		}
		
		return $features;
	}
	
	private function _featuresExists() {
		return array_key_exists('features', $this->data);
	}
}

class ContextFeature {
	public $data;

	public function __construct(array $data) {
		$this->data = $data;
	}
	
	public function __toString() {
		if(array_key_exists('name', $this->data));
			return $this->data['name'];
		
		return '';
	}
	
	public function __get($k) {
		return array_key_exists($k, $this->data) ? $this->data[$k] : NULL;
	}
}

/**
	An adr object can be created from a ContextResult. It will expose properties you would
	expect to exist in an hCard. See http://microformats.org/wiki/adr for more information.
	
	Example Usage:
	$adr = \SimpleGeo\adr::createFromLatLng($geo, 49.239, -123.129);
	echo $adr->countryName;
	echo $adr->region;
	echo $adr->locality;
	echo $adr->locality->license;
	
	@author Aaron Parecki <aaron@parecki.com>
**/
class adr {
	public $postOfficeBox = FALSE;
	public $extendedAddress = FALSE;
	public $streetAddress = FALSE;
	public $locality = FALSE;
	public $region = FALSE;
	public $postalCode = FALSE;
	public $countryName = FALSE;
	
	private $context;

	/**
	 * Create a new adr object given a lat/lng. Requires a SimpleGeo object to be passed in.
	 */
	public static function createFromLatLng(SimpleGeo $sg, $lat, $lng) {
		return new adr(new ContextResult($sg->ContextCoord(new GeoPoint($lat, $lng), array(
			'filter' => 'features',
			'features__category' => 'National,Provincial,Subnational,Urban Area,Municipal,Postal Code'
		))));
	}
	
	public function __construct(ContextResult $c) {
		// Parse the context result into the appropriate fields
		$this->context = $c;
		$this->countryName = 'test';
		
		// Many non-us addresses do not include a "region" in their hCard.
		// In these cases, just a "locality" and "country-name" are used.
		
		if($municipal = $c->getFeatureOfCategory('Municipal')) {
			$this->locality = $municipal;
		} elseif($urbanArea = $c->getFeatureOfCategory('Urban Area')) {
			$this->locality = $urbanArea;
		} elseif($provincial = $c->getFeatureOfCategory('Provincial')) {
			$this->locality = $provincial;
		}
		
		if($subnational = $c->getFeatureOfCategory('Subnational')) {
			$this->region = $subnational;
		} elseif(($provincial = $c->getFeatureOfCategory('Provincial')) && ($provincial != $this->locality)) {
			$this->region = $provincial;
		}
		
		if($country = $c->getFeatureOfCategory('National')) {
			$this->countryName = $country;
		}
		
		if($postalCode = $c->getFeatureOfCategory('Postal Code')) {
			$this->postalCode = $postalCode;
		}
	}
	
	/**
	 * Alias property names for convenience
	 * Can be called with names like:
	 *   $adr->country_name
	 *   $adr->{'country-name'};
	 */
	public function __get($k) {
		// replace hyphens and underscores with spaces
		$k = str_replace(array('-','_'), ' ', $k);
		// capitalize every word
		$k = ucwords($k);
		// remove spaces
		$k = str_replace(' ', '', $k);
		// lowercase the first letter
		$k = lcfirst($k);

		if(property_exists($this, $k))
			return $this->{$k};
		else
			return FALSE;
	}
}


/**
	A record object contains data regarding an arbitrary object and the
	layer it resides in
	http://simplegeo.com/docs/getting-started/storage#what-record
**/
class Record {
	
	private $Properties;
	public $Layer;
	public $ID;
	public $Created;
	public $Latitude;
	public $Longitude;
	
	/**
		Create a record with, location is optional if not inserting/updating
		
		@var	string	$layer	The name of the layer
		@var	string	$id		The unique identifier of the record
		
		@param	double	$lat	Latitude
		@param	double	$lng	Longitude
	**/
	public function Record($layer, $id, $lat = NULL, $lng = NULL) {
		$this->Layer = $layer;
		$this->ID = $id;
		$this->Latitude = $lat;
		$this->Longitude = $lng;
		$this->Properties = array();
		$this->Created = time();
	}
	
	/**
		Returns an array representation of the Record
	**/
	public function ToArray() {
		return array(
			'type' => 'Feature',
			'id' => $this->ID,
			'created' => $this->Created,
			'geometry' => array(
				'type' => 'Point',
				'coordinates' => array($this->Longitude, $this->Latitude),
			),
			'properties' => (object) $this->Properties
		);
	}
	
	
	public function __set($key, $value) {
		$this->Properties[$key] = $value;
	}
	
	public function __get($key) {
		return isset($this->Properties[$key]) ? $this->Properties[$key] : NULL;
	}
	
}

class SimpleGeo extends CURL {

	private $consumer;
	private $token, $secret;
	
	const BASE_URL = 'http://api.simplegeo.com/';
	
	public function __construct($token = false, $secret = false) {
		$this->token = $token;
		$this->secret = $secret;
		$this->consumer = new OAuthConsumer($this->token, $this->secret);
	}
	
	
/**
		Extracts the ID from a SimpleGeo ID (SG_XXXXXXXXXXXXXXXXXXXXXX)
		
		@param	string	id	The SimpleGeo ID of a feaure
	
	**/
	public static function ExtractID($id) {
		preg_match('~SG_[A-Za-z0-9]{22}~', $id, $matches);
		return isset($matches[0]) ? $matches[0] : false;
	}
	
	/**
		Returns a list of all possible feature categories
		
	**/
	public function FeatureCategories() {
		return $this->SendRequest('GET', '1.0/features/categories.json');
	}
	
	/**
		Returns detailed information of a feature
		
		@var string $handle		Feature ID
	**/
		
	public function Feature($handle) {
		return $this->SendRequest('GET', '1.0/features/' . $handle . '.json');
	}
	
	/**
		Returns context of an IP
		
		@var string $ip			IP Address

	**/
	
	public function ContextIP($ip, $opts = false) {
		return $this->SendRequest('GET', '1.0/context/' . $ip . '.json', $opts);
	}
	
	
	
	/**
		Returns context of a coordinate
		
		@var mixed $lat			Latitude or GeoPoint
		@var float $lng			Longitude

	**/
	public function ContextCoord($lat, $lng = false, $opts = false) {
		if ($lat instanceof GeoPoint) {
			if (is_array($lng)) $opts = $lng;
			$lng = $lat->lng;
			$lat = $lat->lat;
		}
		return $this->SendRequest('GET', '1.0/context/' . $lat . ',' . $lng . '.json', $opts);
	}
	
	
	
	/**
		Returns context of an address
		
		@var string $address	Human readable address (US only)

	**/
	public function ContextAddress($address, $opts = false) {
		return $this->SendRequest('GET', '1.0/context/address.json', array('address' => $address));
	}
	
	
	
	/**
		Returns places nearby an IP
		
		@var string $ip			IP Address
		
		@param string q			Search query
		@param string category	Filter by a classifer (see https://gist.github.com/732639)
		@param float radius		Radius in km (default=25)

	**/
	
	public function PlacesIP($ip, $opts = false) {
		return $this->SendRequest('GET', '1.0/places/' . $ip . '.json', $opts);
	}
	
	
	
	/**
		Returns places nearby a coordinate
		
		@var mixed $lat			Latitude or GeoPoint
		@var float $lng			Longitude
		
		
		@param string q			Search query
		@param string category	Filter by a classifer (see https://gist.github.com/732639)
		@param float radius		Radius in km (default=25)

	**/
	public function PlacesCoord($lat, $lng = false, $opts = false) {
		if ($lat instanceof GeoPoint) {
			if (is_array($lng)) $opts = $lng;
			$lng = $lat->lng;
			$lat = $lat->lat;
		}
		return $this->SendRequest('GET', '1.0/places/' . $lat . ',' . $lng . '.json', $opts);
	}
	
	
	
	/**
		Returns places nearby an address
		
		@var string $address	Human readable address (US only)
		
		@param string q			Search query
		@param string category	Filter by a classifer (see https://gist.github.com/732639)
		@param float radius		Radius in km (default=25)

	**/
	public function PlacesAddress($address, $opts = array()) {
		return $this->SendRequest('GET', '1.0/places/address.json', array_merge(array(
			'address' => $address
		), $opts));
	}
	
	
	/**
		Use the Places endpoint to contribute a new feature to SimpleGeo Places. Each new feature 
		gets a unique, consistent handle; if you insert the same record twice, the more recent 
		insert overwrites the previous one.
		
		When you add a feature, it is visible only to your application until approved for general 
		use. To keep your features private to your application, include "private": true in the GeoJSON 
		properties.
	
		@var Feature $place	A place object to insert
		
	**/
	public function CreatePlace(Place $place) {
		$context = $this->ContextCoord($place->Latitude, $place->Longitude);
		return $this->SendRequest('POST', '1.0/places', json_encode($place->ToArray()));
	}
	
	
	/**
		Update a place
	
		@var Feature $place	A place object to modify
		
	**/
	public function UpdatePlace(Place $place) {
		return $this->SendRequest('POST', '1.0/features/' . $place->ID . '.json', json_encode($place->ToArray()));
	}
	
	
	/**
		Suggests that a Feature be deleted and effectively hides it from your view. Requires a handle.
		Returns a status token.
		
		@var Feature $place	The place to delete
	
	**/
	public function DeletePlace(Place $place) {
		return $this->SendRequest('DELETE', '1.0/features/' . $place->ID . '.json');
	}
	

	/**
		Inserts a record according to the ID and Layer properties of the SimpleGeo Storage record
		
		@var	Record	$record	The record to insert
		
	**/
	public function PutRecord(Record $record) {
		return $this->SendRequest('PUT', '0.1/records/' . $record->Layer . '/' . $record->ID . '.json', json_encode($record->ToArray()));
	}
	
	
	
	/**
		Gets a record according to the ID and Layer properties of the SimpleGeo Storage record
		
		@var	Record	$record	The record to retrieve
		
	**/
	public function GetRecord(Record $record) {
		return $this->SendRequest('GET', '0.1/records/' . $record->Layer . '/' . $record->ID . '.json');
	}
	
	
	/**
		Delete a record according to the ID and Layer properties of the SimpleGeo Storage record
		
		@var	Record	$record	The record to delete
		
	**/
	public function DeleteRecord(Record $record) {
		return $this->SendRequest('DELETE', '0.1/records/' . $record->Layer . '/' . $record->ID . '.json');
	}
	
	
	
	/**
		Retrieve the history of an individual Simplegeo Storage record
		
		@var	Record	$record	The record to retrieve the history of
	**/
	public function RecordHistory(Record $record) {
		return $this->SendRequest('GET', '0.1/records/' . $record->Layer . '/' . $record->ID . '/history.json');
	}
	
	
	
	/**
		Retrieve SimpleGeo Storage records nearby the coordinate given
		
		@var	string	$layer	The name of the layer to retrieve records from
		@var	double	$lat	Latitude
		@var	double	$lat	Longitude
		
		@params	array	$params	Additional parameters in an associate array (radius, limit, types, start, end)
		
	**/
	public function NearbyRecordsCoord($layer, $lat, $lng, $params = array()) {
		return $this->SendRequest('GET', '0.1/records/' . $layer . '/nearby/' . $lat . ',' . $lng . '.json', $params);
	}
	
	
	/**
		Returns nearby SimpleGeo Storage records near a street address
		
		@var string $address	Human readable address (US only)
		
		@params	array	$params	Additional parameters in an associate array (radius, limit, types, start, end)

	**/
	public function NearbyRecordsAddress($layer, $address, $params = array()) {
		return $this->SendRequest('GET', '0.1/records/' . $layer . '/nearby/address.json', array_merge(array(
			'address' => $address
		), $params));
	}
	
	
	/**
		Retrieve SimpleGeo Storage records nearby a geohash
		
		@var	string	$layer	The name of the layer to retrieve records from
		@var	string	$hash	The geohash (see geohash.org) of the location
		
		@params	array	$params	Additional parameters in an associate array (radius, limit, types, start, end)
		
	**/
	public function NearbyRecordsGeohash($layer, $hash, $params = array()) {
		return $this->SendRequest('GET', '0.1/records/' . $layer . '/nearby/' . $hash . '.json', $params);
	}
	
	
	
	/**
		Retrieve SimpleGeo Storage records near an IP address
		
		@var	string	$layer	The name of the layer to retrieve records from
		@var	string	$ip		The IP address to search around
		
		@params	array	$params	Additional parameters in an associate array (radius, limit, types, start, end)
		
	**/
	public function NearbyRecordsIP($layer, $ip, $params = array()) {
		return $this->SendRequest('GET', '0.1/records/' . $layer . '/nearby/' . $ip . '.json', $params);
	}

	
	
	/**
		Include the OAuthHeader in the request
		
	**/
	private function IncludeAuthHeader() {
		$request = OAuthRequest::from_consumer_and_token($this->consumer, NULL, $this->Method, $this->GetURL(), NULL);
		$request->sign_request(new OAuthSignatureMethod_HMAC_SHA1(), $this->consumer, NULL);
		$this->SetHeader('Authorization', $request->to_header(true, false));
	}
	
	/**
		Take the results and return a JSON decoded version
		
	**/
	public function GetResults() {
		return json_decode($this->Data, true);
	}
	
	
	private function SendRequest($method = 'GET', $endpoint, $data = array()) {
		$this->Revert(self::BASE_URL . $endpoint);
		$this->SetMethod($method);
		if (is_array($data)) $this->AddVars($data);
		else if (is_string($data)) $this->SetBody($data);
		$this->IncludeAuthHeader();
		return $this->Get();
	}
}

/*

(The MIT License)

Copyright (c) 2011 Rishi Ishairzay <rishi [at] ishairzay [dot] com>
      and (c) 2011 Aaron Parecki <aaron [at] parecki [dot] com>

Permission is hereby granted, free of charge, to any person obtaining
a copy of this software and associated documentation files (the
'Software'), to deal in the Software without restriction, including
without limitation the rights to use, copy, modify, merge, publish,
distribute, sublicense, and/or sell copies of the Software, and to
permit persons to whom the Software is furnished to do so, subject to
the following conditions:

The above copyright notice and this permission notice shall be
included in all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED 'AS IS', WITHOUT WARRANTY OF ANY KIND,
EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF
MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT.
IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY
CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT,
TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE
SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.

*/

