# Piece Analyzer

Note that this repository is currently not very actively maintained as the use for it 
got somhow lost when there was not time to build Lego anymore.
Additionally, other programming languages (say, [R](https://www.r-project.org/)) make it easier and faster just to answer 
such a basic question for myself, at least. 
But feel free to open issue in case you require maintenance, I can restart the project when needed.

This Symfony project can be used to determine the value of a certain Lego set 
and its price/piece ratio. The items can be filtered by category and color 
to help decision making when looking for a possibility to get as many pieces 
of a certain type for as little price as possible.

Note that some data might be fetched by scraping other websites – make sure to get permissions first.

## Installation

Please note: the following information might not be sufficient in case this is your first 
Symfony project – I recommend you checkout some of their gettings started documentation.
In case you have/had troubles, feel free to open an issue so I can help you 
or a PR to improve this README.

Well, well, well – as this is a full web application, you will need a webserver. 
On this webserver, you need a serving software, such as Apache or Nginx. 
And you need a domain to access the site.
Alternatively, you use the local development servers offered by Symfony.

In any case, at some point you will have to download/clone this repository, 
then install all dependencies using `composer install` 
(make sure you have PHP and [composer](https://getcomposer.org/download/) installed for this),
as well as `yarn install` (make sure you have [yarn](https://classic.yarnpkg.com/en/) installed first).

Additionally, you will require a database and put the credentials in a way that Symfony can find them.
Checkout [./.env.dist](./.env.dist) as an overvew over the parameters, which have to be set. 

Then, you will want to build the project: run `./bin/update.sh` to compile all assets etc.

## Usage

If you've come this far, open your browser, point it to the domain where this app is installed, 
and use the app – it's not hard from here.

## Contributions

Are very welcome, in all forms: PRs with improvements to style, 
functionality or just to this README will be gladly reviewed.

## Disclaimer

This program is not endorsed, promoted or otherwise in connection with the Lego group.
