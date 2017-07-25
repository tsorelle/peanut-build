#Peanut Build

The purpose of this project is to assemble fully functional Peanut projects for the various CMS systems
using component projects on GitHub. That is to say, it merges the Tops and Peanut projects with cms specific projects such
as peanut-c5 (Concrete5), peanut-d8 (Drupal 8) and peanut-wp (Wordpress).

Additionally, Wordpress based projects such as QNut are merged with the peanut-wp project.

The whole point is to avoid redundant files stored on GitHub.

The project assumes a directory structure in which the various cms projects are located under the same parent
directory as the build project.  The list of projects and locations are configurable in settings.ini.

##Additional Requirements
1. PHP CLI - version 7.0 
1. NPM package manager - https://www.npmjs.com/
1. Composer - https://getcomposer.org/

##Peanut distribution build

The minimized Peanut distribution files are built using tools\build-peanut-dist.cmd in the pnut-core project.
This tool requires: 
1. Typescript compiler. - https://www.typescriptlang.org/
1. UglifyJS - https://www.npmjs.com/package/uglifyjs
