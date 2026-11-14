/**
 * Where the boot sequence says the visitor comes from — a fiction (LUMN-122).
 *
 * The sequence used to show the visitor's own address, city and carrier, which the server looked up
 * by sending every visitor's address to a third party. Nobody needs them to be true: it is
 * decoration. An origin is now drawn in the browser, from the table below, and nothing about the
 * visitor is read, sent or kept (Laurent's decision, 2026-11-14).
 *
 * The fiction stays coherent: a line is drawn — a country, a city, a carrier, and an address block
 * this carrier really holds in this country — and only then an address inside the block. The
 * sequence shows its first two octets (maskIp()): they are true ones.
 *
 * Every block was checked on RIPEstat, the RIPE NCC's public data service, on 2026-11-14: it is
 * originated by exactly the AS of its line (prefix-overview), and registered in the country of its
 * line (rir-stats-country). `node scripts/verify-boot-origins.js` asks both questions again, for every
 * line: run it before adding one. Where a carrier has been bought or renamed, the line names the holder of
 * record. An address block is rarely reassigned; if one is, the decoration is slightly wrong, not
 * broken. The city is illustrative: a national carrier's block covers many.
 *
 * A draw goes by country first, then by carrier in a city, then by block: every country of Gibson's
 * map comes up as often as any other, however many blocks its carriers hold — Lagos as often as
 * Paris (Laurent: "on ne lésine pas sur le nombre de cas aléatoires possibles").
 */

/**
 * @typedef {object} Origin
 * @property {string} countryCode  ISO 3166-1 alpha-2: a key of ZONES in utils/boot.js
 * @property {string} region       the region code toGibsonLocation() reads for the United States
 * @property {string} city
 * @property {number} asn
 * @property {string} carrier      as the sequence shows it, once upper-cased
 * @property {string} block        an IPv4 block, /16 to /24, its network address first
 */

/**
 * The table, one block per line: country, region, city, AS, carrier as shown, block. Kept as rows
 * so that a line reads as one fact and a review sees every one; ORIGINS gives them their names.
 *
 * @type {readonly [string, string, string, number, string, string][]}
 */
const ROWS = [
    // The Sprawl — the four branches toGibsonLocation() gives the United States.
    ['US', 'NY', 'New York', 701, 'Verizon Business', '100.0.0.0/16'],
    ['US', 'NY', 'New York', 701, 'Verizon Business', '100.1.0.0/16'],
    ['US', 'MA', 'Boston', 7922, 'Comcast Cable', '107.0.0.0/16'],
    ['US', 'MA', 'Boston', 7922, 'Comcast Cable', '107.1.0.0/16'],
    ['US', 'PA', 'Philadelphia', 7922, 'Comcast Cable', '107.2.0.0/16'],
    ['US', 'GA', 'Atlanta', 22773, 'Cox Communications', '137.198.0.0/16'],
    ['US', 'GA', 'Atlanta', 22773, 'Cox Communications', '147.134.0.0/16'],
    ['US', 'NJ', 'Newark', 6128, 'Optimum', '108.58.0.0/16'],
    ['US', 'NJ', 'Newark', 6128, 'Optimum', '148.74.0.0/16'],
    ['US', 'FL', 'Tampa', 33363, 'Charter Communications', '107.144.0.0/16'],
    ['US', 'FL', 'Tampa', 33363, 'Charter Communications', '107.145.0.0/16'],
    ['US', 'NC', 'Charlotte', 11426, 'Charter Communications', '104.138.0.0/16'],
    ['US', 'NC', 'Charlotte', 11426, 'Charter Communications', '104.139.0.0/16'],
    ['US', 'CT', 'Stamford', 5650, 'Frontier Communications', '104.169.0.0/16'],
    ['US', 'CT', 'Stamford', 5650, 'Frontier Communications', '104.226.0.0/16'],
    ['US', 'CA', 'Los Angeles', 20001, 'Charter Communications', '104.172.0.0/16'],
    ['US', 'CA', 'Los Angeles', 20001, 'Charter Communications', '104.173.0.0/16'],
    ['US', 'CA', 'San Francisco', 7018, 'AT&T Services', '104.0.0.0/16'],
    ['US', 'CA', 'San Francisco', 7018, 'AT&T Services', '104.12.0.0/16'],
    ['US', 'WA', 'Seattle', 21928, 'T-Mobile USA', '100.128.0.0/16'],
    ['US', 'WA', 'Seattle', 21928, 'T-Mobile USA', '100.160.0.0/16'],
    ['US', 'OR', 'Portland', 209, 'CenturyLink', '138.210.0.0/16'],
    ['US', 'OR', 'Portland', 209, 'CenturyLink', '144.163.0.0/16'],
    ['US', 'NV', 'Las Vegas', 22773, 'Cox Communications', '174.64.0.0/16'],
    ['US', 'AZ', 'Phoenix', 22773, 'Cox Communications', '174.65.0.0/16'],
    ['US', 'IL', 'Chicago', 7922, 'Comcast Cable', '107.3.0.0/16'],
    ['US', 'OH', 'Columbus', 10796, 'Charter Communications', '107.10.0.0/16'],
    ['US', 'OH', 'Columbus', 10796, 'Charter Communications', '107.11.0.0/16'],
    ['US', 'MI', 'Detroit', 7922, 'Comcast Cable', '107.4.0.0/16'],
    ['US', 'MN', 'Minneapolis', 209, 'CenturyLink', '162.104.0.0/16'],
    ['US', 'MO', 'Saint Louis', 20115, 'Charter Communications', '131.93.0.0/16'],
    ['US', 'MO', 'Saint Louis', 20115, 'Charter Communications', '137.25.0.0/16'],
    ['US', 'WI', 'Milwaukee', 20115, 'Charter Communications', '147.219.0.0/16'],
    ['US', 'TX', 'Austin', 11427, 'Charter Communications', '150.220.0.0/16'],
    ['US', 'TX', 'Austin', 11427, 'Charter Communications', '173.172.0.0/16'],
    ['US', 'CO', 'Denver', 7922, 'Comcast Cable', '107.5.0.0/16'],
    ['US', 'UT', 'Salt Lake City', 209, 'CenturyLink', '168.103.0.0/16'],
    ['US', 'LA', 'New Orleans', 22773, 'Cox Communications', '174.68.0.0/16'],
    ['US', 'HI', 'Honolulu', 20001, 'Charter Communications', '104.174.0.0/16'],
    ['US', 'AK', 'Anchorage', 8047, 'GCI', '24.237.0.0/16'],
    ['US', 'RI', 'Providence', 22773, 'Cox Communications', '174.66.0.0/16'],
    ['US', 'MD', 'Baltimore', 7922, 'Comcast Cable', '147.191.0.0/16'],
    ['US', 'DC', 'Washington', 701, 'Verizon Business', '100.10.0.0/16'],
    ['US', 'VA', 'Virginia Beach', 22773, 'Cox Communications', '174.70.0.0/16'],
    ['US', 'DE', 'Wilmington', 7922, 'Comcast Cable', '162.148.0.0/16'],
    ['US', 'NH', 'Manchester', 7922, 'Comcast Cable', '162.149.0.0/16'],
    ['US', 'VT', 'Burlington', 7922, 'Comcast Cable', '162.150.0.0/16'],
    ['US', 'ME', 'Portland', 11351, 'Charter Communications', '104.228.0.0/16'],
    ['US', 'SC', 'Columbia', 11426, 'Charter Communications', '107.12.0.0/16'],
    ['US', 'AL', 'Birmingham', 20115, 'Charter Communications', '174.86.0.0/16'],
    ['US', 'MS', 'Jackson', 11492, 'Cable One', '216.252.0.0/18'],
    ['US', 'TN', 'Nashville', 7922, 'Comcast Cable', '162.151.0.0/16'],
    ['US', 'IN', 'Indianapolis', 7922, 'Comcast Cable', '162.17.0.0/16'],
    ['US', 'IA', 'Des Moines', 30036, 'Mediacom', '97.64.128.0/17'],
    ['US', 'KS', 'Wichita', 22773, 'Cox Communications', '174.72.0.0/16'],
    ['US', 'NE', 'Omaha', 22773, 'Cox Communications', '174.74.0.0/16'],
    // The rest of the Americas.
    ['CA', 'ON', 'Toronto', 812, 'Rogers Communications', '149.99.0.0/16'],
    ['CA', 'ON', 'Toronto', 812, 'Rogers Communications', '173.32.0.0/16'],
    ['CA', 'QC', 'Montreal', 577, 'Bell Canada', '139.142.0.0/16'],
    ['CA', 'QC', 'Montreal', 577, 'Bell Canada', '142.112.0.0/16'],
    ['CA', 'BC', 'Vancouver', 852, 'Telus', '104.205.0.0/16'],
    ['CA', 'BC', 'Vancouver', 852, 'Telus', '108.172.0.0/16'],
    ['CA', 'AB', 'Calgary', 6327, 'Shaw Communications', '174.0.0.0/16'],
    ['CA', 'AB', 'Calgary', 6327, 'Shaw Communications', '174.1.0.0/16'],
    ['MX', 'CMX', 'Mexico City', 8151, 'Telmex', '148.212.0.0/16'],
    ['MX', 'CMX', 'Mexico City', 8151, 'Telmex', '148.217.0.0/16'],
    ['MX', 'CMX', 'Mexico City', 8151, 'Telmex', '148.219.0.0/16'],
    ['MX', 'NLE', 'Monterrey', 13999, 'Megacable', '177.224.0.0/16'],
    ['MX', 'NLE', 'Monterrey', 13999, 'Megacable', '177.226.0.0/16'],
    ['MX', 'JAL', 'Guadalajara', 6503, 'Axtel', '148.240.0.0/16'],
    ['MX', 'JAL', 'Guadalajara', 6503, 'Axtel', '148.243.0.0/16'],
    ['BR', 'SP', 'Sao Paulo', 28573, 'Claro', '177.140.0.0/16'],
    ['BR', 'SP', 'Sao Paulo', 28573, 'Claro', '177.141.0.0/16'],
    ['BR', 'SP', 'Sao Paulo', 27699, 'Vivo', '152.249.0.0/16'],
    ['BR', 'SP', 'Sao Paulo', 27699, 'Vivo', '152.250.0.0/16'],
    ['BR', 'RJ', 'Rio de Janeiro', 7738, 'Oi', '187.12.0.0/16'],
    ['BR', 'RJ', 'Rio de Janeiro', 7738, 'Oi', '187.125.0.0/16'],
    ['AR', 'C', 'Buenos Aires', 7303, 'Telecom Argentina', '152.168.0.0/16'],
    ['AR', 'C', 'Buenos Aires', 7303, 'Telecom Argentina', '152.169.0.0/16'],
    ['AR', 'C', 'Buenos Aires', 22927, 'Telefonica de Argentina', '168.226.0.0/16'],
    ['AR', 'C', 'Buenos Aires', 22927, 'Telefonica de Argentina', '179.36.0.0/16'],
    ['CL', 'RM', 'Santiago', 7418, 'Movistar Chile', '152.172.0.0/16'],
    ['CL', 'RM', 'Santiago', 7418, 'Movistar Chile', '152.173.0.0/16'],
    ['CL', 'RM', 'Santiago', 22047, 'VTR', '179.2.0.0/16'],
    ['CL', 'RM', 'Santiago', 22047, 'VTR', '190.100.0.0/16'],
    ['CL', 'RM', 'Santiago', 27651, 'Entel', '164.77.0.0/16'],
    ['CL', 'RM', 'Santiago', 27651, 'Entel', '200.111.0.0/16'],
    ['CO', 'DC', 'Bogota', 3816, 'Movistar Colombia', '186.116.0.0/16'],
    ['CO', 'DC', 'Bogota', 3816, 'Movistar Colombia', '186.117.0.0/16'],
    ['CO', 'DC', 'Bogota', 10620, 'Claro Colombia', '186.85.16.0/20'],
    ['CO', 'DC', 'Bogota', 10620, 'Claro Colombia', '181.50.56.0/21'],
    ['CO', 'ANT', 'Medellin', 13489, 'UNE', '177.252.0.0/16'],
    ['CO', 'ANT', 'Medellin', 13489, 'UNE', '177.253.0.0/16'],
    // Europe.
    ['FR', 'IDF', 'Paris', 12322, 'Free', '213.36.0.0/16'],
    ['FR', 'IDF', 'Paris', 12322, 'Free', '62.147.0.0/16'],
    ['FR', 'IDF', 'Paris', 12322, 'Free', '78.192.0.0/16'],
    ['FR', 'IDF', 'Paris', 3215, 'Orange', '109.208.0.0/16'],
    ['FR', 'IDF', 'Paris', 3215, 'Orange', '109.211.0.0/16'],
    ['FR', 'IDF', 'Paris', 3215, 'Orange', '109.212.0.0/16'],
    ['FR', 'IDF', 'Paris', 5410, 'Bouygues Telecom', '128.78.0.0/16'],
    ['FR', 'IDF', 'Paris', 5410, 'Bouygues Telecom', '128.79.0.0/16'],
    ['FR', 'IDF', 'Paris', 15557, 'SFR', '109.0.0.0/16'],
    ['FR', 'IDF', 'Paris', 15557, 'SFR', '109.16.0.0/16'],
    ['GB', 'ENG', 'London', 2856, 'British Telecommunications', '109.144.0.0/16'],
    ['GB', 'ENG', 'London', 2856, 'British Telecommunications', '109.148.0.0/16'],
    ['GB', 'ENG', 'London', 2856, 'British Telecommunications', '109.152.0.0/16'],
    ['GB', 'ENG', 'London', 5089, 'Virgin Media', '194.168.0.0/16'],
    ['GB', 'ENG', 'London', 5089, 'Virgin Media', '195.188.0.0/16'],
    ['GB', 'ENG', 'London', 13285, 'TalkTalk', '2.100.0.0/16'],
    ['GB', 'ENG', 'London', 13285, 'TalkTalk', '2.101.0.0/16'],
    ['GB', 'ENG', 'London', 5607, 'Sky Broadband', '149.241.0.0/16'],
    ['GB', 'ENG', 'London', 5607, 'Sky Broadband', '151.224.0.0/16'],
    ['DE', 'HE', 'Frankfurt', 3320, 'Deutsche Telekom', '164.19.0.0/16'],
    ['DE', 'HE', 'Frankfurt', 3320, 'Deutsche Telekom', '193.158.0.0/16'],
    ['DE', 'HE', 'Frankfurt', 3320, 'Deutsche Telekom', '193.159.0.0/16'],
    ['DE', 'NW', 'Duesseldorf', 3209, 'Vodafone', '109.192.0.0/16'],
    ['DE', 'NW', 'Duesseldorf', 3209, 'Vodafone', '109.193.0.0/16'],
    ['DE', 'BY', 'Munich', 6805, 'Telefonica Germany', '176.0.0.0/16'],
    ['DE', 'BY', 'Munich', 6805, 'Telefonica Germany', '176.2.0.0/16'],
    ['DE', 'NW', 'Dortmund', 8881, '1&1 Versatel', '109.250.0.0/16'],
    ['DE', 'NW', 'Dortmund', 8881, '1&1 Versatel', '46.142.0.0/16'],
    ['IT', 'MI', 'Milan', 3269, 'Telecom Italia', '131.1.0.0/16'],
    ['IT', 'MI', 'Milan', 3269, 'Telecom Italia', '151.99.0.0/16'],
    ['IT', 'MI', 'Milan', 3269, 'Telecom Italia', '188.10.0.0/16'],
    ['IT', 'MI', 'Milan', 30722, 'Vodafone Italia', '109.112.0.0/16'],
    ['IT', 'MI', 'Milan', 30722, 'Vodafone Italia', '109.113.0.0/16'],
    ['IT', 'MI', 'Milan', 12874, 'Fastweb', '2.224.0.0/16'],
    ['IT', 'MI', 'Milan', 12874, 'Fastweb', '2.226.0.0/16'],
    ['IT', 'RM', 'Rome', 1267, 'Wind Tre', '151.10.0.0/16'],
    ['IT', 'RM', 'Rome', 1267, 'Wind Tre', '151.11.0.0/16'],
    ['ES', 'CT', 'Barcelona', 3352, 'Telefonica de Espana', '176.80.0.0/16'],
    ['ES', 'CT', 'Barcelona', 3352, 'Telefonica de Espana', '176.82.0.0/16'],
    ['ES', 'CT', 'Barcelona', 3352, 'Telefonica de Espana', '176.83.0.0/16'],
    ['ES', 'MD', 'Madrid', 12479, 'Orange Espagne', '149.74.0.0/16'],
    ['ES', 'MD', 'Madrid', 12479, 'Orange Espagne', '188.76.0.0/16'],
    ['ES', 'MD', 'Madrid', 12430, 'Vodafone Espana', '137.101.0.0/16'],
    ['ES', 'MD', 'Madrid', 12430, 'Vodafone Espana', '148.3.0.0/16'],
    ['PT', '11', 'Lisbon', 3243, 'MEO', '144.64.0.0/16'],
    ['PT', '11', 'Lisbon', 3243, 'MEO', '176.78.0.0/16'],
    ['PT', '11', 'Lisbon', 2860, 'NOS', '109.48.0.0/16'],
    ['PT', '11', 'Lisbon', 2860, 'NOS', '109.49.0.0/16'],
    ['PT', '11', 'Lisbon', 12353, 'Vodafone Portugal', '148.63.0.0/16'],
    ['PT', '11', 'Lisbon', 12353, 'Vodafone Portugal', '148.69.0.0/16'],
    ['NL', 'NH', 'Amsterdam', 1136, 'KPN', '137.17.0.0/16'],
    ['NL', 'NH', 'Amsterdam', 1136, 'KPN', '144.44.0.0/16'],
    ['NL', 'NH', 'Amsterdam', 33915, 'VodafoneZiggo', '109.32.0.0/16'],
    ['NL', 'NH', 'Amsterdam', 33915, 'VodafoneZiggo', '109.34.0.0/16'],
    ['NL', 'ZH', 'The Hague', 50266, 'Odido', '143.177.0.0/16'],
    ['NL', 'ZH', 'The Hague', 50266, 'Odido', '143.179.0.0/16'],
    ['BE', 'VAN', 'Antwerp', 5432, 'Proximus', '109.128.0.0/16'],
    ['BE', 'VAN', 'Antwerp', 5432, 'Proximus', '109.129.0.0/16'],
    ['BE', 'VAN', 'Antwerp', 6848, 'Telenet', '141.134.0.0/16'],
    ['BE', 'VAN', 'Antwerp', 6848, 'Telenet', '141.135.0.0/16'],
    ['BE', 'WLG', 'Liege', 12392, 'VOO', '109.88.0.0/16'],
    ['BE', 'WLG', 'Liege', 12392, 'VOO', '109.89.0.0/16'],
    ['CH', 'BE', 'Bern', 3303, 'Swisscom', '138.188.0.0/16'],
    ['CH', 'BE', 'Bern', 3303, 'Swisscom', '138.190.0.0/16'],
    ['CH', 'ZH', 'Zurich', 6730, 'Sunrise', '178.38.0.0/16'],
    ['CH', 'ZH', 'Zurich', 6730, 'Sunrise', '178.39.0.0/16'],
    ['AT', '9', 'Vienna', 8447, 'A1 Telekom Austria', '178.188.0.0/16'],
    ['AT', '9', 'Vienna', 8447, 'A1 Telekom Austria', '178.189.0.0/16'],
    ['AT', '9', 'Vienna', 8412, 'Magenta Telekom', '212.186.0.0/16'],
    ['AT', '9', 'Vienna', 8412, 'Magenta Telekom', '46.124.0.0/16'],
    ['AT', '9', 'Vienna', 25255, 'Drei', '178.112.0.0/16'],
    ['AT', '9', 'Vienna', 25255, 'Drei', '178.113.0.0/16'],
    ['SE', 'AB', 'Stockholm', 3301, 'Telia', '148.136.0.0/16'],
    ['SE', 'AB', 'Stockholm', 3301, 'Telia', '159.193.0.0/16'],
    ['SE', 'AB', 'Stockholm', 1257, 'Tele2', '130.244.0.0/16'],
    ['SE', 'AB', 'Stockholm', 1257, 'Tele2', '150.227.0.0/16'],
    ['SE', 'AB', 'Stockholm', 8473, 'Bahnhof', '98.128.0.0/16'],
    ['SE', 'AB', 'Stockholm', 8473, 'Bahnhof', '176.10.128.0/17'],
    ['NO', '03', 'Oslo', 2119, 'Telenor', '109.179.0.0/16'],
    ['NO', '03', 'Oslo', 2119, 'Telenor', '109.189.0.0/16'],
    ['NO', '03', 'Oslo', 29695, 'Altibox', '109.247.0.0/16'],
    ['NO', '03', 'Oslo', 29695, 'Altibox', '139.164.0.0/16'],
    ['FI', '18', 'Helsinki', 719, 'Elisa', '139.97.0.0/16'],
    ['FI', '18', 'Helsinki', 719, 'Elisa', '193.184.0.0/16'],
    ['FI', '18', 'Helsinki', 1759, 'Telia Finland', '109.240.0.0/16'],
    ['FI', '18', 'Helsinki', 1759, 'Telia Finland', '131.177.0.0/16'],
    ['FI', '18', 'Helsinki', 16086, 'DNA', '146.211.0.0/16'],
    ['FI', '18', 'Helsinki', 16086, 'DNA', '157.200.0.0/16'],
    ['DK', '84', 'Copenhagen', 3292, 'TDC', '131.164.0.0/16'],
    ['DK', '84', 'Copenhagen', 3292, 'TDC', '176.21.0.0/16'],
    ['DK', '84', 'Copenhagen', 9158, 'Telenor Denmark', '129.142.0.0/16'],
    ['DK', '84', 'Copenhagen', 9158, 'Telenor Denmark', '130.227.0.0/16'],
    ['PL', '14', 'Warsaw', 5617, 'Orange Polska', '178.42.0.0/16'],
    ['PL', '14', 'Warsaw', 5617, 'Orange Polska', '178.43.0.0/16'],
    ['PL', '14', 'Warsaw', 5617, 'Orange Polska', '178.56.0.0/16'],
    ['PL', '14', 'Warsaw', 12741, 'Netia', '77.252.0.0/16'],
    ['PL', '14', 'Warsaw', 12741, 'Netia', '78.10.0.0/16'],
    ['PL', '14', 'Warsaw', 29314, 'Vectra', '178.235.0.0/17'],
    ['PL', '14', 'Warsaw', 29314, 'Vectra', '31.11.128.0/17'],
    ['CZ', '10', 'Prague', 5610, 'O2 Czech Republic', '109.80.0.0/16'],
    ['CZ', '10', 'Prague', 5610, 'O2 Czech Republic', '109.81.0.0/16'],
    ['CZ', '10', 'Prague', 16019, 'Vodafone Czech Republic', '31.30.0.0/16'],
    ['CZ', '10', 'Prague', 16019, 'Vodafone Czech Republic', '46.135.0.0/16'],
    ['HU', 'BU', 'Budapest', 5483, 'Magyar Telekom', '145.236.0.0/16'],
    ['HU', 'BU', 'Budapest', 5483, 'Magyar Telekom', '158.249.0.0/16'],
    ['HU', 'BU', 'Budapest', 20845, 'Digi Hungary', '85.66.0.0/16'],
    ['HU', 'BU', 'Budapest', 20845, 'Digi Hungary', '85.67.0.0/16'],
    ['HU', 'BU', 'Budapest', 21334, 'Vodafone Hungary', '176.63.0.0/16'],
    ['HU', 'BU', 'Budapest', 21334, 'Vodafone Hungary', '178.48.0.0/16'],
    ['RO', 'B', 'Bucharest', 8708, 'Digi Romania', '188.24.0.0/16'],
    ['RO', 'B', 'Bucharest', 8708, 'Digi Romania', '188.25.0.0/16'],
    ['RO', 'B', 'Bucharest', 8708, 'Digi Romania', '188.27.0.0/16'],
    ['RO', 'B', 'Bucharest', 9050, 'Telekom Romania', '109.100.0.0/16'],
    ['RO', 'B', 'Bucharest', 9050, 'Telekom Romania', '109.101.0.0/16'],
    ['UA', '30', 'Kyiv', 6849, 'Ukrtelecom', '46.200.0.0/16'],
    ['UA', '30', 'Kyiv', 6849, 'Ukrtelecom', '46.201.0.0/17'],
    ['UA', '30', 'Kyiv', 15895, 'Kyivstar', '134.249.0.0/16'],
    ['UA', '30', 'Kyiv', 15895, 'Kyivstar', '176.8.0.0/16'],
    ['RU', 'MOW', 'Moscow', 12389, 'Rostelecom', '109.184.0.0/16'],
    ['RU', 'MOW', 'Moscow', 12389, 'Rostelecom', '176.209.0.0/16'],
    ['RU', 'MOW', 'Moscow', 12389, 'Rostelecom', '176.210.0.0/16'],
    ['RU', 'MOW', 'Moscow', 8359, 'MTS', '178.141.0.0/16'],
    ['RU', 'MOW', 'Moscow', 8359, 'MTS', '213.87.0.0/16'],
    ['RU', 'MOW', 'Moscow', 3216, 'Beeline', '128.71.0.0/16'],
    ['RU', 'MOW', 'Moscow', 3216, 'Beeline', '194.186.0.0/16'],
    // Istanbul, the Gulf and the Levant.
    ['TR', '34', 'Istanbul', 9121, 'Turk Telekom', '195.174.0.0/16'],
    ['TR', '34', 'Istanbul', 9121, 'Turk Telekom', '195.175.0.0/16'],
    ['TR', '34', 'Istanbul', 9121, 'Turk Telekom', '212.156.0.0/16'],
    ['TR', '34', 'Istanbul', 34984, 'Superonline', '151.250.0.0/16'],
    ['TR', '34', 'Istanbul', 34984, 'Superonline', '176.232.0.0/16'],
    ['TR', '34', 'Istanbul', 16135, 'Turkcell', '178.242.0.0/16'],
    ['TR', '34', 'Istanbul', 16135, 'Turkcell', '176.90.0.0/17'],
    ['AE', 'DU', 'Dubai', 5384, 'Etisalat', '109.177.0.0/16'],
    ['AE', 'DU', 'Dubai', 5384, 'Etisalat', '151.253.0.0/16'],
    ['AE', 'DU', 'Dubai', 15802, 'du', '5.30.0.0/16'],
    ['AE', 'DU', 'Dubai', 15802, 'du', '5.31.0.0/16'],
    ['SA', '01', 'Riyadh', 25019, 'Saudi Telecom', '128.234.0.0/16'],
    ['SA', '01', 'Riyadh', 25019, 'Saudi Telecom', '129.208.0.0/16'],
    ['SA', '01', 'Riyadh', 35819, 'Mobily', '146.251.0.0/16'],
    ['SA', '01', 'Riyadh', 35819, 'Mobily', '37.141.0.0/16'],
    ['IL', 'TA', 'Tel Aviv', 8551, 'Bezeq International', '212.179.0.0/16'],
    ['IL', 'TA', 'Tel Aviv', 8551, 'Bezeq International', '31.168.0.0/16'],
    ['IL', 'TA', 'Tel Aviv', 1680, 'Partner', '109.186.0.0/16'],
    ['IL', 'TA', 'Tel Aviv', 1680, 'Partner', '109.253.0.0/16'],
    ['IL', 'TA', 'Tel Aviv', 12849, 'HOT', '213.57.0.0/16'],
    ['IL', 'TA', 'Tel Aviv', 12849, 'HOT', '37.142.0.0/16'],
    ['IR', '23', 'Tehran', 58224, 'Iran Telecom', '2.176.0.0/16'],
    ['IR', '23', 'Tehran', 58224, 'Iran Telecom', '2.177.0.0/16'],
    ['IR', '23', 'Tehran', 197207, 'MCI', '204.18.0.0/16'],
    ['IR', '23', 'Tehran', 197207, 'MCI', '37.129.0.0/16'],
    // Chiba and the rest of the Rim.
    ['JP', '12', 'Chiba', 4713, 'NTT Communications', '114.144.0.0/16'],
    ['JP', '12', 'Chiba', 4713, 'NTT Communications', '114.148.0.0/16'],
    ['JP', '12', 'Chiba', 4713, 'NTT Communications', '114.152.0.0/16'],
    ['JP', '13', 'Tokyo', 2516, 'KDDI', '106.128.0.0/16'],
    ['JP', '13', 'Tokyo', 2516, 'KDDI', '106.136.0.0/16'],
    ['JP', '13', 'Tokyo', 17676, 'SoftBank', '1.112.0.0/16'],
    ['JP', '13', 'Tokyo', 17676, 'SoftBank', '1.113.0.0/16'],
    ['KR', '11', 'Seoul', 4766, 'Korea Telecom', '1.100.0.0/16'],
    ['KR', '11', 'Seoul', 4766, 'Korea Telecom', '1.101.0.0/16'],
    ['KR', '11', 'Seoul', 4766, 'Korea Telecom', '1.102.0.0/16'],
    ['KR', '11', 'Seoul', 9318, 'SK Broadband', '1.224.0.0/16'],
    ['KR', '11', 'Seoul', 9318, 'SK Broadband', '1.226.0.0/16'],
    ['KR', '11', 'Seoul', 3786, 'LG Uplus', '1.208.0.0/16'],
    ['KR', '11', 'Seoul', 3786, 'LG Uplus', '1.212.0.0/16'],
    ['CN', 'SH', 'Shanghai', 4134, 'China Telecom', '1.180.0.0/16'],
    ['CN', 'SH', 'Shanghai', 4134, 'China Telecom', '1.181.0.0/16'],
    ['CN', 'SH', 'Shanghai', 4134, 'China Telecom', '1.182.0.0/16'],
    ['CN', 'BJ', 'Beijing', 4837, 'China Unicom', '1.188.0.0/16'],
    ['CN', 'BJ', 'Beijing', 4837, 'China Unicom', '1.189.0.0/16'],
    ['CN', 'GD', 'Shenzhen', 9808, 'China Mobile', '110.100.0.0/16'],
    ['CN', 'GD', 'Shenzhen', 9808, 'China Mobile', '110.116.0.0/16'],
    ['HK', 'HK', 'Hong Kong', 4760, 'PCCW Netvigator', '1.36.0.0/16'],
    ['HK', 'HK', 'Hong Kong', 4760, 'PCCW Netvigator', '1.64.0.0/16'],
    ['HK', 'HK', 'Hong Kong', 9269, 'HKBN', '119.246.0.0/16'],
    ['HK', 'HK', 'Hong Kong', 9269, 'HKBN', '119.247.0.0/16'],
    ['HK', 'HK', 'Hong Kong', 9304, 'HGC', '113.252.0.0/16'],
    ['HK', 'HK', 'Hong Kong', 9304, 'HGC', '113.253.0.0/16'],
    ['TW', 'TPE', 'Taipei', 3462, 'HiNet', '1.160.0.0/16'],
    ['TW', 'TPE', 'Taipei', 3462, 'HiNet', '1.161.0.0/16'],
    ['TW', 'TPE', 'Taipei', 3462, 'HiNet', '1.162.0.0/16'],
    ['TW', 'TPE', 'Taipei', 9924, 'Taiwan Fixed Network', '124.10.0.0/16'],
    ['TW', 'TPE', 'Taipei', 9924, 'Taiwan Fixed Network', '124.11.0.0/16'],
    ['SG', '01', 'Singapore', 3758, 'Singtel', '165.21.0.0/16'],
    ['SG', '01', 'Singapore', 3758, 'Singtel', '203.125.0.0/16'],
    ['SG', '01', 'Singapore', 4657, 'StarHub', '101.127.0.0/16'],
    ['SG', '01', 'Singapore', 4657, 'StarHub', '171.0.0.0/16'],
    ['TH', '10', 'Bangkok', 7470, 'True Internet', '110.170.0.0/16'],
    ['TH', '10', 'Bangkok', 7470, 'True Internet', '110.171.0.0/16'],
    ['TH', '10', 'Bangkok', 45758, '3BB', '110.164.0.0/16'],
    ['TH', '10', 'Bangkok', 45758, '3BB', '171.4.0.0/16'],
    ['TH', '10', 'Bangkok', 23969, 'TOT', '1.20.0.0/16'],
    ['TH', '10', 'Bangkok', 23969, 'TOT', '101.108.0.0/16'],
    ['VN', 'SG', 'Ho Chi Minh City', 7552, 'Viettel', '115.72.0.0/16'],
    ['VN', 'SG', 'Ho Chi Minh City', 7552, 'Viettel', '115.73.0.0/16'],
    ['VN', 'SG', 'Ho Chi Minh City', 45899, 'VNPT', '113.160.0.0/16'],
    ['VN', 'SG', 'Ho Chi Minh City', 45899, 'VNPT', '113.161.0.0/16'],
    ['VN', 'SG', 'Ho Chi Minh City', 18403, 'FPT Telecom', '1.52.0.0/16'],
    ['VN', 'SG', 'Ho Chi Minh City', 18403, 'FPT Telecom', '1.53.0.0/16'],
    ['IN', 'MH', 'Mumbai', 9498, 'Bharti Airtel', '122.166.0.0/16'],
    ['IN', 'MH', 'Mumbai', 9498, 'Bharti Airtel', '122.174.0.0/16'],
    ['IN', 'MH', 'Mumbai', 55836, 'Reliance Jio', '115.240.0.0/16'],
    ['IN', 'MH', 'Mumbai', 55836, 'Reliance Jio', '115.241.0.0/16'],
    ['IN', 'MH', 'Mumbai', 9829, 'BSNL', '117.192.0.0/16'],
    ['IN', 'MH', 'Mumbai', 9829, 'BSNL', '117.193.0.0/16'],
    ['AU', 'NSW', 'Sydney', 1221, 'Telstra', '1.120.0.0/16'],
    ['AU', 'NSW', 'Sydney', 1221, 'Telstra', '1.122.0.0/16'],
    ['AU', 'NSW', 'Sydney', 1221, 'Telstra', '1.124.0.0/16'],
    ['AU', 'NSW', 'Sydney', 4804, 'Optus', '1.40.0.0/16'],
    ['AU', 'NSW', 'Sydney', 4804, 'Optus', '1.41.0.0/16'],
    ['AU', 'NSW', 'Sydney', 7545, 'TPG Telecom', '110.174.0.0/16'],
    ['AU', 'NSW', 'Sydney', 7545, 'TPG Telecom', '110.175.0.0/16'],
    ['NZ', 'AUK', 'Auckland', 4771, 'Spark', '125.236.192.0/19'],
    ['NZ', 'AUK', 'Auckland', 4771, 'Spark', '222.154.224.0/19'],
    ['NZ', 'AUK', 'Auckland', 9500, 'One NZ', '118.92.0.0/16'],
    ['NZ', 'AUK', 'Auckland', 9500, 'One NZ', '118.93.0.0/16'],
    // Africa.
    ['ZA', 'WC', 'Cape Town', 37457, 'Telkom SA', '102.248.0.0/16'],
    ['ZA', 'WC', 'Cape Town', 37457, 'Telkom SA', '102.249.0.0/16'],
    ['ZA', 'WC', 'Cape Town', 36994, 'Vodacom', '105.242.0.0/16'],
    ['ZA', 'WC', 'Cape Town', 36994, 'Vodacom', '105.243.0.0/16'],
    ['ZA', 'WC', 'Cape Town', 16637, 'MTN South Africa', '105.208.0.0/16'],
    ['ZA', 'WC', 'Cape Town', 16637, 'MTN South Africa', '105.210.0.0/16'],
    ['NG', 'LA', 'Lagos', 29465, 'MTN Nigeria', '102.88.0.0/16'],
    ['NG', 'LA', 'Lagos', 29465, 'MTN Nigeria', '102.90.0.0/16'],
    ['NG', 'LA', 'Lagos', 36873, 'Airtel Nigeria', '105.114.0.0/16'],
    ['NG', 'LA', 'Lagos', 36873, 'Airtel Nigeria', '105.115.0.0/16'],
    ['NG', 'LA', 'Lagos', 37148, 'Globacom', '129.205.96.0/19'],
    ['NG', 'LA', 'Lagos', 37148, 'Globacom', '197.211.32.0/19'],
    ['EG', 'C', 'Cairo', 8452, 'TE Data', '102.40.0.0/16'],
    ['EG', 'C', 'Cairo', 8452, 'TE Data', '102.42.0.0/16'],
    ['EG', 'C', 'Cairo', 24863, 'Link Egypt', '197.160.0.0/16'],
    ['EG', 'C', 'Cairo', 24863, 'Link Egypt', '197.161.0.0/16'],
    ['EG', 'C', 'Cairo', 36992, 'Etisalat Misr', '102.56.0.0/16'],
    ['EG', 'C', 'Cairo', 36992, 'Etisalat Misr', '102.58.0.0/16'],
    ['MA', '06', 'Casablanca', 6713, 'Maroc Telecom', '102.48.0.0/16'],
    ['MA', '06', 'Casablanca', 6713, 'Maroc Telecom', '102.50.0.0/16'],
    ['MA', '06', 'Casablanca', 36925, 'Orange Maroc', '102.100.0.0/16'],
    ['MA', '06', 'Casablanca', 36925, 'Orange Maroc', '102.101.0.0/16'],
    ['KE', '30', 'Nairobi', 33771, 'Safaricom', '105.160.0.0/16'],
    ['KE', '30', 'Nairobi', 33771, 'Safaricom', '105.162.0.0/16'],
    ['KE', '30', 'Nairobi', 36926, 'Airtel Kenya', '105.230.0.0/16'],
    ['KE', '30', 'Nairobi', 36926, 'Airtel Kenya', '105.231.0.0/16'],
];

/** @type {readonly Origin[]} — 334 blocks */
export const ORIGINS = Object.freeze(
    ROWS.map(([countryCode, region, city, asn, carrier, block]) =>
        Object.freeze({ countryCode, region, city, asn, carrier, block }),
    ),
);

/**
 * Groups a list by a key, in the order the keys first appear. Map.groupBy does the same, but only
 * since Safari 17.4: on an older iPhone, this module would fail to load, and every island with it.
 *
 * @template T
 * @param {readonly T[]} list
 * @param {(item: T) => string} keyOf
 * @returns {readonly (readonly T[])[]}
 */
function groupBy(list, keyOf) {
    const groups = new Map();
    for (const item of list) {
        const key = keyOf(item);
        if (!groups.has(key)) groups.set(key, []);
        groups.get(key).push(item);
    }
    return Object.freeze([...groups.values()].map((group) => Object.freeze(group)));
}

/**
 * The table as a draw walks it: countries, each with its carriers in a city, each with its blocks.
 * Built once, in the order of the table.
 *
 * @type {readonly (readonly (readonly Origin[])[])[]}
 */
export const ORIGIN_TREE = Object.freeze(
    groupBy(ORIGINS, (origin) => origin.countryCode).map((country) =>
        groupBy(country, (origin) => `${origin.asn} ${origin.city}`),
    ),
);

/** One element of a list, as a fraction of [0, 1) picks it. */
function pick(list, random) {
    return list[Math.floor(random() * list.length)];
}

/** @param {string} address dotted IPv4 */
function toNumber(address) {
    return address.split('.').reduce((total, octet) => total * 256 + Number(octet), 0);
}

/** @param {number} value */
function toDotted(value) {
    return [24, 16, 8, 0].map((shift) => Math.floor(value / 2 ** shift) % 256).join('.');
}

/**
 * Draws an origin, in the shape toGibsonLocation() reads: a country, a carrier in a city there, one
 * of its blocks, then an address inside the block.
 *
 * @param {() => number} random  in [0, 1), as Math.random — called four times
 * @returns {{ query: string, city: string, countryCode: string, region: string, as: string }}
 */
export function drawOrigin(random) {
    const origin = pick(pick(pick(ORIGIN_TREE, random), random), random);
    const [network, length] = origin.block.split('/');
    const host = Math.floor(random() * 2 ** (32 - Number(length)));
    return {
        query: toDotted(toNumber(network) + host),
        city: origin.city,
        countryCode: origin.countryCode,
        region: origin.region,
        as: `AS${origin.asn} ${origin.carrier}`,
    };
}
