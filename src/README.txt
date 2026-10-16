heLMSman: a small course site for one instructor
================================================

Install
1. Upload this whole folder to your website (you can rename it, e.g. "courses").
   Include the hidden files .htaccess, .user.ini and lib/.htaccess.
2. Open the folder in your browser. The first visit creates your instructor account.
3. Click "Import Canvas course (.imscc)", or create a blank course.

Requirements: PHP 7.4 or newer with XML support, and a folder PHP can write to.

Your data (courses, accounts, submissions, grades) is kept in the "data" folder,
which the site creates. Back it up regularly. Updates never touch it.

Site > Server check shows what your host supports and whether the data folder is
protected from direct download.

Full documentation, license and source: see README.md in the project repository.
License: MIT (see LICENSE.txt).
