package testing;

import org.testng.Assert;
import org.testng.annotations.*;
import org.openqa.selenium.WebDriver;
import org.openqa.selenium.WebElement;
import org.openqa.selenium.By;
import org.openqa.selenium.Alert;
import org.openqa.selenium.chrome.ChromeDriver;
import org.openqa.selenium.chrome.ChromeOptions;
import org.openqa.selenium.support.ui.WebDriverWait;
import org.openqa.selenium.support.ui.ExpectedConditions;

import io.github.bonigarcia.wdm.WebDriverManager;
import java.time.Duration;
import java.util.Random;

/**
 * TestNG test class for testing the Smart Quiz Portal registration
 * functionality
 * 
 * This class contains focused tests for:
 * - Successful student registration
 * - Failed student registration
 * - Successful teacher registration
 * - Failed teacher registration
 */
public class RegisterTest {

  private WebDriver driver;
  private WebDriverWait wait;
  private String baseUrl = "http://localhost/smartquizportal"; // Update with your actual URL
  private Random random = new Random();

  @BeforeClass
  public void setUp() {
    // Set up ChromeDriver using WebDriverManager (automatic driver management)
    WebDriverManager.chromedriver().setup();

    // Configure Chrome options
    ChromeOptions options = new ChromeOptions();
    options.addArguments("--disable-blink-features=AutomationControlled");
    options.addArguments("--disable-extensions");
    options.addArguments("--no-sandbox");
    options.addArguments("--disable-dev-shm-usage");
    // Uncomment the next line to run in headless mode
    // options.addArguments("--headless");

    driver = new ChromeDriver(options);
    wait = new WebDriverWait(driver, Duration.ofSeconds(10));
    driver.manage().window().maximize();
    driver.manage().timeouts().implicitlyWait(Duration.ofSeconds(5));
  }

  @BeforeMethod
  public void navigateToRegisterPage() {
    driver.get(baseUrl + "/auth/register.php");
    // Wait for page to load
    wait.until(ExpectedConditions.presenceOfElementLocated(By.name("username")));
  }

  @Test(priority = 1, description = "Test successful student registration with valid data")
  public void testSuccessfulStudentRegistration() {
    String uniqueId = String.valueOf(System.currentTimeMillis());
    String username = "student_" + uniqueId;
    String email = "student" + uniqueId + "@test.com";

    // Fill registration form using individual findElement and sendKeys
    WebElement nameField = driver.findElement(By.name("name"));
    nameField.clear();
    nameField.sendKeys("Test Student " + uniqueId);

    WebElement usernameField = driver.findElement(By.name("username"));
    usernameField.clear();
    usernameField.sendKeys(username);

    WebElement emailField = driver.findElement(By.name("email"));
    emailField.clear();
    emailField.sendKeys(email);

    WebElement passwordField = driver.findElement(By.name("password"));
    passwordField.clear();
    passwordField.sendKeys("password123");

    WebElement confirmPasswordField = driver.findElement(By.name("confirm_password"));
    confirmPasswordField.clear();
    confirmPasswordField.sendKeys("password123");

    // Select student role using radio button
    WebElement studentRoleRadio = driver.findElement(By.id("role_student"));
    studentRoleRadio.click();

    // Wait before submitting form
    try {
      Thread.sleep(2000); // Wait 2 seconds
    } catch (InterruptedException e) {
      Thread.currentThread().interrupt();
    }

    // Submit form
    WebElement submitBtn = driver.findElement(By.cssSelector("button.btn-register"));
    submitBtn.click();

    // Verify successful registration
    wait.until(ExpectedConditions.urlContains("login.php"));

    // Check for success message or redirect to login
    String currentUrl = driver.getCurrentUrl();
    Assert.assertTrue(currentUrl.contains("login.php"),
        "Successful student registration should redirect to login page");
  }

  @Test(priority = 2, description = "Test failed student registration with invalid data")
  public void testFailedStudentRegistration() {
    String uniqueId = String.valueOf(System.currentTimeMillis());

    // Fill registration form with invalid data (mismatched passwords)
    WebElement nameField = driver.findElement(By.name("name"));
    nameField.clear();
    nameField.sendKeys("Test Student " + uniqueId);

    WebElement usernameField = driver.findElement(By.name("username"));
    usernameField.clear();
    usernameField.sendKeys("student_" + uniqueId);

    WebElement emailField = driver.findElement(By.name("email"));
    emailField.clear();
    emailField.sendKeys("student" + uniqueId + "@test.com");

    WebElement passwordField = driver.findElement(By.name("password"));
    passwordField.clear();
    passwordField.sendKeys("password123");

    WebElement confirmPasswordField = driver.findElement(By.name("confirm_password"));
    confirmPasswordField.clear();
    confirmPasswordField.sendKeys("differentpassword"); // Mismatched password

    // Select student role using radio button
    WebElement studentRoleRadio = driver.findElement(By.id("role_student"));
    studentRoleRadio.click();

    // Wait before submitting form
    try {
      Thread.sleep(2000); // Wait 2 seconds
    } catch (InterruptedException e) {
      Thread.currentThread().interrupt();
    }

    // Submit form
    WebElement submitBtn = driver.findElement(By.cssSelector("button.btn-register"));
    submitBtn.click();

    // Verify failed registration - check for alert box
    wait.until(ExpectedConditions.alertIsPresent());
    Alert alert = driver.switchTo().alert();
    String alertText = alert.getText();
    alert.accept(); // Close the alert

    Assert.assertFalse(alertText.isEmpty(),
        "Failed student registration should show alert message");
  }

  @Test(priority = 3, description = "Test successful teacher registration with valid data")
  public void testSuccessfulTeacherRegistration() {
    String uniqueId = String.valueOf(System.currentTimeMillis());
    String username = "teacher_" + uniqueId;
    String email = "teacher" + uniqueId + "@test.com";

    // Fill registration form using individual findElement and sendKeys
    WebElement nameField = driver.findElement(By.name("name"));
    nameField.clear();
    nameField.sendKeys("Test Teacher " + uniqueId);

    WebElement usernameField = driver.findElement(By.name("username"));
    usernameField.clear();
    usernameField.sendKeys(username);

    WebElement emailField = driver.findElement(By.name("email"));
    emailField.clear();
    emailField.sendKeys(email);

    WebElement passwordField = driver.findElement(By.name("password"));
    passwordField.clear();
    passwordField.sendKeys("password123");

    WebElement confirmPasswordField = driver.findElement(By.name("confirm_password"));
    confirmPasswordField.clear();
    confirmPasswordField.sendKeys("password123");

    // Select teacher role using radio button
    WebElement teacherRoleRadio = driver.findElement(By.id("role_teacher"));
    teacherRoleRadio.click();

    // Wait before submitting form
    try {
      Thread.sleep(2000); // Wait 2 seconds
    } catch (InterruptedException e) {
      Thread.currentThread().interrupt();
    }

    // Submit form
    WebElement submitBtn = driver.findElement(By.cssSelector("button.btn-register"));
    submitBtn.click();

    // Verify successful registration
    wait.until(ExpectedConditions.urlContains("login.php"));

    String currentUrl = driver.getCurrentUrl();
    Assert.assertTrue(currentUrl.contains("login.php"),
        "Successful teacher registration should redirect to login page");
  }

  @Test(priority = 4, description = "Test failed teacher registration with invalid data")
  public void testFailedTeacherRegistration() {
    String uniqueId = String.valueOf(System.currentTimeMillis());

    // Fill registration form with invalid data (invalid email format)
    WebElement nameField = driver.findElement(By.name("name"));
    nameField.clear();
    nameField.sendKeys("Test Teacher " + uniqueId);

    WebElement usernameField = driver.findElement(By.name("username"));
    usernameField.clear();
    usernameField.sendKeys("teacher_" + uniqueId);

    WebElement emailField = driver.findElement(By.name("email"));
    emailField.clear();
    emailField.sendKeys("invalid-email-format"); // Invalid email

    WebElement passwordField = driver.findElement(By.name("password"));
    passwordField.clear();
    passwordField.sendKeys("password123");

    WebElement confirmPasswordField = driver.findElement(By.name("confirm_password"));
    confirmPasswordField.clear();
    confirmPasswordField.sendKeys("password123");

    // Select teacher role using radio button
    WebElement teacherRoleRadio = driver.findElement(By.id("role_teacher"));
    teacherRoleRadio.click();

    // Wait before submitting form
    try {
      Thread.sleep(2000); // Wait 2 seconds
    } catch (InterruptedException e) {
      Thread.currentThread().interrupt();
    }

    WebElement submitBtn = driver.findElement(By.cssSelector("button.btn-register"));
    submitBtn.click();

    // Verify failed registration - check for alert box
    wait.until(ExpectedConditions.alertIsPresent());
    Alert alert = driver.switchTo().alert();
    String alertText = alert.getText();
    alert.accept(); // Close the alert

    Assert.assertFalse(alertText.isEmpty(),
        "Failed teacher registration should show alert message");
  }

  @AfterClass
  public void tearDown() {
    if (driver != null) {
      driver.quit();
    }
  }
}