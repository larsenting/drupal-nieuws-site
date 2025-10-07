
let stars = 
    document.getElementsByClassName("star");
let output = 
    document.getElementById("output");

function gfg(n) {
  let stars = document.querySelectorAll('.star');
  stars.forEach(star => star.classList.remove('selected'));
  for (let i = 0; i < n; i++) {
    stars[stars.length - 1 - i].classList.add('selected');
  }
  document.getElementById("output").innerText = "Beoordeling is: " + n + "/5";
}

function remove() {
    let i = 0;
    while (i < 5) {
        stars[i].className = "star";
        i++;
    }
}
