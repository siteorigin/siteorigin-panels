var baseUrl = require('./config.js').baseUrl,
    webdriver = require('selenium-webdriver'),
    Key = webdriver.Key,
    By = webdriver.By,
    until = webdriver.until,
    assert = require('assert'),
    chrome = require('selenium-webdriver/chrome'),
    test = require('selenium-webdriver/testing'),
    chai = require('chai'),
    expect = chai.expect;
chai.use(require('chai-as-promised'));

test.describe('Page Builder', function() {
    this.timeout(0);//Disable mocha timeouts
    var driver;

    test.before(function () {
        driver = new chrome.Driver();
        driver.get(baseUrl + 'wp-login.php');
        driver.findElement(By.id('user_login')).sendKeys('admin');
        driver.findElement(By.id('user_pass')).sendKeys('password');
        driver.findElement(By.id('wp-submit')).click();
        expect(driver.getTitle()).to.eventually.contain('Dashboard');
    });

    test.after(function () {
        driver.quit();
    });

    test.it('should create the Page Builder tab.', function () {
        driver.get(baseUrl + 'wp-admin/post-new.php?post_type=page');
        driver.wait(until.titleContains('Add New Page'), 500);
        driver.wait(until.elementLocated(By.id('content-panels')), 500);
    });

    test.it('should successfully add a row.', function () {
        driver.get(baseUrl + 'wp-admin/post-new.php?post_type=page');
        driver.wait(until.elementLocated(By.id('content-panels')), 500);
        driver.findElement(By.id('content-panels')).click();
        driver.wait(until.elementLocated(By.className('so-row-add')), 500);
        driver.findElement(By.className('so-row-add')).click();
        driver.wait(until.elementLocated(By.className('so-panels-dialog-wrapper')), 500);
        driver.wait(until.elementLocated(By.className('so-insert')));
        driver.findElement(By.className('so-insert')).click();
        driver.wait(until.elementLocated(By.className('so-row-container')), 500);
    });

    test.it('should successfully edit a row.', function () {
        driver.get(baseUrl + 'wp-admin/post.php?post=410&action=edit');
        driver.wait(until.elementLocated(By.css('span.so-dropdown-wrapper > a.so-row-settings')), 500);
        driver.findElement(By.css('span.so-dropdown-wrapper > a.so-row-settings')).click();
        driver.wait(until.elementLocated(By.css('div.so-content.panel-dialog > div.row-set-form > input[name="cells"]')), 500);
        driver.findElement(By.css('div.so-content.panel-dialog > div.row-set-form > input[name="cells"]')).sendKeys(Key.chord(Key.CONTROL, 'a'), '3');
        driver.findElement(By.className('so-save')).click();
        driver.wait(until.elementLocated(By.css('div.so-row-container > div.so-cells')), 500);
        driver.findElements(By.css('div.so-row-container > div.so-cells > div.cell')).then(function (cells) {
            assert.equal(cells.length, 3, 'Did not add one cell.');
        });
    });

    test.it('should successfully add a widget.', function () {
        driver.get(baseUrl + 'wp-admin/post-new.php?post_type=page');
        driver.wait(until.elementLocated(By.id('content-panels')), 500);
        driver.findElement(By.id('content-panels')).click();
        driver.wait(until.elementLocated(By.className('so-widget-add')), 500);
        driver.findElement(By.className('so-widget-add')).click();
        driver.wait(until.elementLocated(By.className('so-panels-dialog-wrapper')), 500);
        driver.findElement(By.js('return jQuery(\'li.widget-type h3:contains("Text")\')')).click(); //0_o
        driver.wait(until.elementLocated(By.css('.so-row-container > .so-cells > .cell > .cell-wrapper > .so-widget')), 500);
    });

    test.it('should successfully move a widget.', function () {
        driver.get(baseUrl + 'wp-admin/post.php?post=410&action=edit');
        var ofWidget = '.so-row-container > .so-cells > .cell > .cell-wrapper > .so-widget';
        driver.wait(until.elementLocated(By.css(ofWidget)), 500);
        var emptyCell = driver.findElement(By.js('return jQuery(\'.so-row-container > .so-cells > .cell > .cell-wrapper\')[1];')); //0_o
        driver.actions().dragAndDrop(driver.findElement(By.css(ofWidget)), emptyCell).perform();
    });
});